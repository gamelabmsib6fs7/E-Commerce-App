<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    public function addToCart(Request $request) {
        $product = Product::with('product_images')->find($request->id);

        if ($product == null) {
            return response()->json([
                'status' => false,
                'message' => 'Produk tidak ditemukan'
            ]);
        }

        if (Cart::count() > 0) {
            //echo "Product already in cart";
            // Produk ketemu di keranjang
            // Cek produk ini sudah dikeranjang atau belum
            // Return as message bahwa produk sudah ditambahkan di keranjang Anda
            // Jika produk tidak ditemukan di keranjang, tambahkan produk di keranjang

            $cartContent = Cart::content();
            $productAlreadyExist = false;

            foreach ($cartContent as $item) {
                if ($item->id == $product->id) {
                    $productAlreadyExist = true;
                }
            }

            if ($productAlreadyExist == false) {
                Cart::add($product->id, $product->title, 1, $product->price, ['productImage' => (!empty
                ($product->product_images)) ? $product->product_images->first() : '']);

                $status = true;
                $message = '<strong>'.$product->title.'</strong> ditambah di keranjang.';
                session()->flash('success',$message);
            
            } else {
                $status = false;
                $message = $product->title." Sudah ditambah di keranjang";
            }


        } else {
            Cart::add($product->id, $product->title, 1, $product->price, ['productImage' => (!empty($product->product_images)) ? $product->product_images->first() : '']);
            $status = true;
            $message = '<strong>'.$product->title.'</strong> ditambah di keranjang.';
            session()->flash('success',$message);
        }

        return response()->json([
            'status' => $status,
            'message' => $message
        ]);

    }

    public function cart() {
        $cartContent = Cart::content();
        //dd($cartContent);
        $data['cartContent'] = $cartContent;
        return view('front.cart', $data);
    }

    public function updateCart(Request $request) {
        $rowId = $request->rowId;
        $qty = $request->qty;

        $itemInfo = Cart::get($rowId);

        $product = Product::find($itemInfo->id);
        // check qty available in stock

        if ($product->track_qty == 'Yes') {
            if ($qty <= $product->qty) {
                Cart::update($rowId, $qty);
                $message = 'Keranjang sukses di Update';  
                $status = true;
                session()->flash('success',$message);
            } else {
                $message = 'Requested qty('.$qty.') not available in stock.';
                $status = false;
                session()->flash('error',$message);
            }
        } else {
            Cart::update($rowId, $qty);
            $message = 'Keranjang sukses di Update';  
            $status = true;
            session()->flash('success',$message);
        }

        session()->flash('success', $message);
        return response()->json([
            'status' => $status,
            'message' => $message
        ]);
    }

    public function deleteItem(Request $request) {
        
        $itemInfo = Cart::get($request->rowId);

        if($itemInfo == null) {
            $errorMessage = 'Tidak ada item di keranjang';
            session()->flash('error',$errorMessage);
            
            return response()->json([
                'status' => false,
                'message' => $errorMessage
            ]);
        }
        Cart::remove($request->rowId);
        
        $message = 'Item berhasil dihapus dari keranjang.';
        
        session()->flash('success',$message);

        return response()->json([
            'status' => true,
            'message' => $message
        ]);
    }

    public function checkout() {

        //-- if cart empty redirect to cart page
        if (Cart::count() == 0) {
            return redirect()->route('front.cart');
        }

        //-- if user is not logged in then redirect to login page
        if (Auth::check() == false) {
            
            if (!session()->has('url.intended')) {
                session(['url.intended' => url()->current()]);
            }
            
            return redirect()->route('account.login');
        }
        
        $customerAddress = CustomerAddress::where('user_id',Auth::user()->id)->first();



        session()->forget('url.intended');

        $countries = Country::orderBy('name','ASC')->get();

        return view('front.checkout',[
            'countries' => $countries,
            'customerAddress'=> $customerAddress
        ]);
    }

    public function processCheckout(Request $request) {

        // step - 1 Apply Validation

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|min:5',
            'last_name' => 'required',
            'email' => 'required|email',
            'country' => 'required',
            'address' => 'required|min:30',
            'city' => 'required',
            'state' => 'required',
            'zip' => 'required',
            'mobile' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Tolong perbaiki erronya',
                'status' => false,
                'errors' => $validator->errors()
            ]);
        }

        // step - 2 save user address

        $user = Auth::user();

        CustomerAddress::updateOrCreate(
            ['user_id' => $user->id],
            [
                'user_id' => $user->id,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'mobile' => $request->mobile,
                'country_id' => $request->country,
                'address' => $request->address,
                'apartment' => $request->apartment,
                'city' => $request->city,
                'state' => $request->state,
                'zip' => $request->zip,
            ]
        );

        // step - 3 store data in orders table

        if ($request->payment_method == 'cod') {

            $shipping = 0;
            $discount = 0;
            $subTotal = Cart::subtotal(2,'.','');
            $grandTotal = $subTotal+$shipping;
            
            $order = new Order;
            $order->subtotal = $subTotal;
            $order->shipping = $shipping;
            $order->grand_total = $grandTotal;
            $order->user_id = $user->id;
            $order->first_name = $request->first_name;
            $order->last_name = $request->last_name;
            $order->email = $request->email;
            $order->mobile = $request->mobile;
            $order->address = $request->address;
            $order->apartment = $request->apartment;
            $order->state = $request->state;
            $order->city = $request->city;
            $order->zip = $request->zip;
            $order->notes = $request->notes;
            $order->country_id = $request->country;
            $order->save();

            // step - 4 store order items in orderite, table
            foreach (Cart::content() as $item) {
                $orderItem = new OrderItem;
                $orderItem->product_id = $item->id;
                $orderItem->order_id = $order->id;
                $orderItem->name = $item->name;
                $orderItem->qty = $item->qty;
                $orderItem->price = $item->price;
                $orderItem->total = $item->price*$item->qty;
                $orderItem->save();
            }

            session()->flash('success','Orderan anda sudah sukses tersimpan');
            
            Cart::destroy();

            return response()->json([
                'message' => 'Order Sukses Tersimpan',
                'orderId' => $order->id,
                'status' => true,
            ]);
            
        } else {

        }
    }

    public function thankyou($id) {
        return view('front.thanks',[
            'id' => $id
        ]);
    }
   
}