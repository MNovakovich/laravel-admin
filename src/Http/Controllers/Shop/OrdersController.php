<?php

namespace SystemInc\LaravelAdmin\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mail;
use PDF;
use SystemInc\LaravelAdmin\Order;
use SystemInc\LaravelAdmin\OrderItem;
use SystemInc\LaravelAdmin\OrderStatus;
use SystemInc\LaravelAdmin\Product;
use SystemInc\LaravelAdmin\Validations\UpdatedOrderValidation;
use Validator;

class OrdersController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function getIndex()
    {
        $orders = Order::orderBy('created_at', 'desc')->paginate(15);

        return view('admin::orders.index', compact('orders'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $order_id
     *
     * @return \Illuminate\Http\Response
     */
    public function getEdit($order_id)
    {
        $order = Order::find($order_id);

        $statuses = OrderStatus::all();

        $products = Product::all();

        $max_invoice_number = (int) Order::whereRaw('YEAR(created_at)='.date('Y'))->max('invoice_number');

        return view('admin::orders.order', compact('order', 'max_invoice_number', 'statuses', 'products'));
    }

    /**
     * Store changes.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function postSave(Request $request, $order_id)
    {
        // validation
        $validation = Validator::make($request->all(), UpdatedOrderValidation::rules(), UpdatedOrderValidation::messages());

        if ($validation->fails()) {
            return back()->withInput()->withErrors($validation);
        }

        $order = Order::find($order_id);
        $old_order_status_id = $order->order_status_id;

        if ($request->invoice_number) {
            $order_with_same_invoice_number = Order::whereRaw('YEAR(created_at)='.$order->created_at->format('Y'))
                ->where('invoice_number', $request->invoice_number)
                ->where('id', '<>', $order->id)
                ->first();

            if ($order_with_same_invoice_number) {
                return back()->with('error', 'Invoice number already taken by Order '.$order_with_same_invoice_number->id);
            }
        }

        $order->update($request->all());

        if (empty($request->valid_until)) {
            $order->valid_until = null;
        }
        if (empty($request->date_of_purchase)) {
            $order->date_of_purchase = null;
        }

        if (empty($request->show_shipping_address)) {
            $order->show_shipping_address = 0;
        }

        $order->recalculateTotalPrice();
        $order->save();

        // deduct delivered products from stock
        if ($order->order_status_id == 5 && $order->order_status_id != $old_order_status_id) {
            foreach ($order->items as $item) {
                $item->product->stock--;
                $item->product->save();
            }
        }

        return back()->with('success', 'Saved successfully');
    }

    /**
     * Add item.
     *
     * @param Request $request
     * @param int     $order_id
     *
     * @return \Illuminate\Http\Response
     */
    public function postAddItem(Request $request, $order_id)
    {
        $order = Order::find($order_id);
        $product = Product::find($request->product_id);

        $item = OrderItem::whereProductId($request->product_id)->first();

        if ($item) {
            $item->quantity += 1;
        } else {
            $item = OrderItem::create([
                'order_id'     => $order->id,
                'product_id'   => $product->id,
                'custom_price' => 0,
            ]);
        }

        $item->save();

        $order->items()->save($item);
        $order->recalculateTotalPrice();
        $order->save();

        return back()->with('success', $product->title.' added');
    }

    /**
     * Delete item.
     *
     * @param Request $request
     * @param int     $item_id
     *
     * @return \Illuminate\Http\Response
     */
    public function getDeleteItem(Request $request, $item_id)
    {
        $item = OrderItem::find($item_id);
        $product = $item->product;

        $item->delete();

        return back()->with('success', $product->title.' deleted');
    }

    /**
     * Edit item.
     *
     * @param Request $request
     * @param int     $item_id
     *
     * @return \Illuminate\Http\Response
     */
    public function postEditItem(Request $request, $item_id)
    {
        $item = OrderItem::find($item_id);

        $item->quantity = $request->quantity;
        $item->discount = $request->discount;
        $item->custom_price = $request->custom_price;

        $item->save();

        $item->order->recalculateTotalPrice();
        $item->order->save();

        return back()->with('success', $item->product->title.' edited');
    }

    /**
     * Preview PDF proforma.
     *
     * @param int $order_id
     *
     * @return \Illuminate\Http\Response
     */
    public function getPreviewProforma($order_id)
    {
        $data = ['order' => Order::find($order_id), 'type' => 'proforma'];

        $pdf = PDF::loadView('admin::pdf.invoice', $data);

        return $pdf->stream('invoice.pdf');
    }

    /**
     * Preview PDF invoice.
     *
     * @param int $order_id
     *
     * @return \Illuminate\Http\Response
     */
    public function getPreviewInvoice($order_id)
    {
        $data = ['order' => Order::find($order_id), 'type' => 'invoice'];

        $pdf = PDF::loadView('admin::pdf.invoice', $data);

        return $pdf->stream('invoice.pdf');
    }

    /**
     * Send PDF proforma.
     *
     * @param Request $request
     * @param int     $order_id
     *
     * @return \Illuminate\Http\Response
     */
    public function getSendProforma(Request $request, $order_id)
    {
        $order = Order::find($order_id);
        $type = 'proforma';

        $pdf_path = storage_path('invoices/'.$type.'-'.$order_id.'.pdf');
        PDF::loadView('admin::pdf.invoice', compact('order', 'type'))->save($pdf_path);

        // send email
        Mail::send('admin::mail.invoice', ['type' => 'proforma'], function ($m) use ($order, $pdf_path) {
            $m->to($order->billing_email, $order->billing_name)->subject("Proforma invoice No: T-{$order->id}-".date('Y'))->attach($pdf_path);
        });

        $order->order_status_id = 2;
        $order->save();

        return back()->with('success', 'Invoice sent');
    }

    /**
     * Send PDF invoice.
     *
     * @param Request $request
     * @param int     $order_id
     *
     * @return \Illuminate\Http\Response
     */
    public function getSendInvoice(Request $request, $order_id)
    {
        $order = Order::find($order_id);
        $type = 'invoice';

        $max_invoice_number = (int) Order::whereRaw('YEAR(created_at)='.date('Y'))->max('invoice_number');

        $order->invoice_number = $max_invoice_number + 1;
        $order->order_status_id = 4;
        $order->save();

        $pdf_path = storage_path('invoices/'.$type.'-'.$order_id.'.pdf');
        PDF::loadView('admin::pdf.invoice', compact('order', 'type'))->save($pdf_path);

        // send email
        Mail::send('admin::mail.invoice', ['type' => 'invoice'], function ($m) use ($order, $pdf_path) {
            $m->to($order->billing_email, $order->billing_name)->subject("Invoice No: {$order->invoice_number} ".date('Y'))->attach($pdf_path);
        });

        return back()->with('success', 'Invoice sent');
    }

    /**
     * Print PDF invoice.
     *
     * @param Request $request
     * @param int     $order_id
     *
     * @return \Illuminate\Http\Response
     */
    public function getPrintInvoice(Request $request, $order_id)
    {
        $order = Order::find($order_id);
        $type = 'invoice';

        $max_invoice_number = (int) Order::whereRaw('YEAR(created_at)='.date('Y'))->max('invoice_number');

        $order->invoice_number = $max_invoice_number + 1;
        $order->save();

        return PDF::loadView('admin::pdf.invoice', compact('order', 'type'))->download($type.'-'.$order_id.'.pdf');
    }
}
