<?php

namespace App\Http\Controllers\Orders;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use App\Models\Orders\Order;
use App\Models\Orders\OrderStatus;
use App\Models\Rates\Rate;
use App\User;
use Validator;
use Illuminate\Validation\Rule;
use App\Notifications\NewOrder;
use App\Notifications\TakeOrder;
use App\Notifications\FinalizeOrder;

class OrderController extends Controller
{
    public function all (Request $request)
    {
        return Order::with('status', 'code', 'client', 'dealer')->latest()->get();
    }

    public function availables ()
    {
        $ordersCount = Order::where('dealer_id', Auth::id())->where('status_id', 2)->count();
        if ($ordersCount > 0) {
            return Order::with('status', 'code', 'client')->where('dealer_id', Auth::id())->where('status_id', 2)->get();
        } else {
            return Order::with('status', 'code', 'client')->where('status_id', 1)->latest()->get();
        }
    }

    public function availablesCount ()
    {
        return Order::where('status_id', 1)->count();
    }

    public function byClient ($userId)
    {
        return Order::with('status', 'code', 'dealer')->where('client_id', $userId)->latest()->get();
    }

    public function byDealer ($userId)
    {
        return Order::with('status', 'code', 'client')->where('dealer_id', $userId)->latest()->get();
    }

    public function show ($orderId)
    {
        return Order::with('status', 'code', 'client', 'dealer')->findOrFail($orderId);
    }

    public function store (Request $request)
    {
        $this->validate($request, [
            'order' => 'required|string',
            'address' => 'required|array',
            'client' => 'required|array'
        ]);

        if ($request->dealer) {
            $status = 2;
        } else {
            $status = 1;
        }

        $order = Order::create([
            'order' => $request->order,
            'address' => $request->address['address'],
            'status_id' => $status,
            'client_id' => $request->client['id'],
            'dealer_id' => $request->dealer['id'],
            'rate' => Rate::latest()->first()->rate
        ]);

        Auth::user()->notify(new NewOrder($order));

        return $this->show($order->id);
    }

    public function storeAuth (Request $request)
    {
        $this->validate($request, [
            'order' => 'required|string',
            'address' => 'required|array',
            //'cellphone' => 'numeric|nullable'
        ]);

        Validator::make($request->all(), [
            'cellphone' => Rule::requiredIf($request->user()->cellphone == null),
        ])->validate();

        if ($request->cellphone) {
            $user = User::find(Auth::id());
            $user->cellphone = $request->cellphone;
            $user->save();
        }

        $order = Order::create([
            'order' => $request->order,
            'address' => $request->address['address'],
            'status_id' => 1,
            'client_id' => Auth::id(),
            'rate' => Rate::latest()->first()->rate
        ]);

        Auth::user()->notify(new NewOrder($order));

        // Send more than one
        // $users = User::role('superadmin', 'admin', 'dealer')->get();
        // Notification::send($users, new NewOrder($message));

        return $order;
    }

    public function updateStatus ($orderId, Request $request)
    {
        $this->validate($request, [
            'statusId' => 'required|integer'
        ]);

        $order = Order::find($orderId);

        if ($order->status_id != $request->statusId) {
            $order->status_id = $request->statusId;
        }

        $order->save();

        if ($order->status_id == 3) {
            Auth::user()->notify(new FinalizeOrder($order));
        }

        return $this->show($order->id);
    }

    public function updateScoreClient ($orderId, Request $request)
    {
        $this->validate($request, [
            'scoreClient' => 'required|integer'
        ]);

        $order = Order::find($orderId);

        if ($order->score_client != $request->scoreClient) {
            $order->score_client = $request->scoreClient;
        }

        $order->save();

        $user = User::find($order->dealer_id);
        $user->orders_total++;
        $user->score_sum = $user->score_sum + $request->scoreClient;
        $user->score = $user->score_sum / $user->orders_total;
        $user->save();

        return $order;
    }

    public function updateScoreDealer ($orderId, Request $request)
    {
        $this->validate($request, [
            'scoreDealer' => 'required|integer'
        ]);

        $order = Order::find($orderId);

        if ($order->score_dealer != $request->scoreDealer) {
            $order->score_dealer = $request->scoreDealer;
        }

        $order->save();

        $user = User::find($order->client_id);
        $user->orders_total++;
        $user->score_sum = $user->score_sum + $request->scoreDealer;
        $user->score = $user->score_sum / $user->orders_total;
        $user->save();

        return $order;
    }

    public function takeOrder ($orderId, Request $request)
    {
        $order = Order::find($orderId);

        $order->status_id = 2;
        $order->dealer_id = Auth::id();

        $order->save();

        Auth::user()->notify(new TakeOrder($order));

        return $order;
    }

    public function assignDealer ($orderId, Request $request)
    {
        $this->validate($request, [
            'dealer' => 'required'
        ]);

        $order = Order::find($orderId);
        $order->dealer_id = $request->dealer['id'];
        $order->status_id = 2;
        $order->save();

        Auth::user()->notify(new TakeOrder($order));

        return $this->show($order->id);
    }

    public function status ()
    {
        return OrderStatus::orderBy('id', 'asc')->get();
    }
}
