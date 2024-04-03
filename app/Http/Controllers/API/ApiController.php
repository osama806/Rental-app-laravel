<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\RealEstate;
use App\Models\Reserve;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

class ApiController extends Controller
{
    public function all_estates(): HttpResponse
    {
        $estates = RealEstate::all();
        $data = [];
        foreach ($estates as $estate) {
            $data[] = [
                "id"          =>    $estate->id,
                "type"        =>    $estate->type,
                "price"       =>    $estate->price,
                "beds"        =>    $estate->beds,
                "address"     =>    $estate->address,
                "reserved"    =>    $estate->reserved,
                "rented"      =>    $estate->rented
            ];
        }
        return response([
            "isSuccess" => true,
            "estates" => $data
        ], 200);
    }

    public function estate(string $id): HttpResponse
    {
        $estate = RealEstate::find($id);
        if (!$estate) {
            return response([
                "isSuccess" => false,
                "msg" => "This estate isn't found"
            ], 200);
        }
        $data = [
            "owner"            =>    $estate->owner,
            "broker"           =>    $estate->broker,
            "type"             =>    $estate->type,
            "price"            =>    $estate->price,
            "beds"             =>    $estate->beds,
            "paths"            =>    $estate->paths,
            "address"          =>    $estate->address,
            "state"            =>    $estate->state,
            "locality"         =>    $estate->locality,
            "sub_locality"     =>    $estate->sub_locality,
            "street_name"      =>    $estate->street_name,
            "reserved"         =>    $estate->reserved,
            "rented"           =>    $estate->rented
        ];
        return response([
            "isSuccess" => true,
            "estate" => $data
        ], 200);
    }

    public function realEstate_reservation(Request $request): HttpResponse
    {
        $user = User::find(auth()->user()->id);
        $estate = RealEstate::find($request->estate_id);
        if (!$estate) {
            return response([
                "isSuccess" => false,
                "msg" => "This estate isn't found"
            ], 200);
        }
        if ($estate->reserved == "yes") {
            return response([
                "isSuccess" => false,
                "msg" => "This estate is already reserved"
            ], 200);
        }
        if ($estate->rented == "yes") {
            return response([
                "isSuccess" => false,
                "msg" => "This estate is already rented"
            ], 200);
        }
        // قيمة رعبون حجز العقار تساوي ثلث قيمة العقار الكلية
        $reservation_deposit = intval($estate->price / 3);
        if ($user->balance < $reservation_deposit) {
            return response([
                "isSuccess"         =>       false,
                "msg"               =>       "You haven't balance enough"
            ], 200);
        }
        $reserve = Reserve::where("user_id", "=", auth()->user()->id)->where("estate_id", "=", $estate->id)->first();
        if (!$reserve) {
            $date = Carbon::now();
            // مدة حجز العقار هي اسبوع حتى تكملة الدفعة
            $reserve_record = Reserve::create([
                "user_id"                 =>      auth()->user()->id,
                "estate_id"               =>      $estate->id,
                "reservation_deposit"     =>      $reservation_deposit,
                "expired_date"            =>      $date->addDays(7)
            ]);
            if (!$reserve_record) {
                $reserve_record->delete();
                return response([
                    "isSuccess"             =>      false,
                    "msg"                   =>      "The reservation isn't install"
                ], 200);
            } else {
                $estate->reserved = "yes";
                $estate->save();
                $user->balance -= $reservation_deposit;
                $user->save();
                return response([
                    "isSuccess"             =>      true,
                    "msg"                   =>      "The reservation is installed"
                ], 200);
            }
        } else {
            $date = Carbon::parse($reserve->expired_date);
            if ($date->isFuture()) {
                return response([
                    "isSuccess"           =>      false,
                    "msg"                 =>      "Your previous booking isn't expired"
                ], 200);
            }
            $date = Carbon::now();
            $user->balance -= $reservation_deposit;
            $user->save();
            $reserve->reservation_deposit = $reservation_deposit;
            $reserve->expired_date = $date->addDays(7);
            $estate->reserved = "yes";
            $estate->save();
            $reserve->save();
            return response([
                "isSuccess"         =>      true,
                "msg"               =>      "The reservation is installed"
            ], 200);
        }
    }

    public function reservation_cancel(string $id): HttpResponse
    {
        $user = User::find(auth()->user()->id);
        $reservation = Reserve::where("user_id", "=", auth()->user()->id)->where("id", "=", $id)->first();
        if (!$reservation) {
            return response([
                "isSuccess"         =>          false,
                "msg"               =>          "Reservation isn't found"
            ], 200);
        }
        $date = Carbon::parse($reservation->expired_date);
        if (!$date->isFuture()) {
            $reservation->estate->reserved = "no";
            $reservation->estate->save();
            return response([
                "isSuccess"         =>          false,
                "msg"               =>          "Your reservation is previous expired"
            ], 200);
        }
        // غرامة الغاء الحجز تساوي نصف قيمة الحجز
        $cancellation_fine = intval($reservation->reservation_deposit / 2);
        $reservation->estate->reserved = "no";
        $reservation->estate->save();
        $date = Carbon::now();
        $reservation->expired_date = $date;
        $reservation->save();
        $user->balance += $cancellation_fine;
        $user->save();
        return response([
            "isSuccess"         =>          true,
            "msg"               =>          "The reservation is canceled"
        ], 200);
    }

    public function all_reservations(): HttpResponse
    {
        $reservations = Reserve::where("user_id", auth()->user()->id)->get();
        if ($reservations->count() < 1) {
            return response([
                "isSuccess"     =>      false,
                "msg"           =>      "You haven't any reserve yet"
            ], 200);
        }
        $data = [];
        foreach ($reservations as $reservation) {
            $data[] = [
                "id"                      =>      $reservation->id,
                "user_name"               =>      $reservation->user->name,
                "estate_id"               =>      $reservation->estate_id,
                "reservation_deposit"     =>      $reservation->reservation_deposit,
                "expired_date"            =>      $reservation->expired_date
            ];
        }
        return response([
            "isSuccess"         =>      true,
            "reservations"      =>      $data
        ], 200);
    }

    public function create_contract(Request $request): HttpResponse
    {
        $user = User::find(auth()->user()->id);
        $estate = RealEstate::where("id", "=", $request->estate_id)->first();
        if (!$estate) {
            return response([
                "isSuccess"       =>      false,
                "msg"             =>      "This estate isn't found"
            ], 200);
        }
        if ($estate->rented == "yes") {
            return response([
                "isSuccess"       =>      false,
                "msg"             =>      "This estate is currently rented"
            ], 200);
        }
        $reserve = Reserve::where("user_id", auth()->user()->id)->where("estate_id", $request->estate_id)->first();
        if (!$reserve) {
            return response([
                "isSuccess"       =>      false,
                "msg"             =>      "You haven't reservation for this estate"
            ], 200);
        }
        $date = Carbon::parse($reserve->expired_date);
        if (!$date->isFuture()) {
            $estate->reserved = "no";
            $estate->save();
            return response([
                "isSuccess"       =>      false,
                "msg"             =>      "Your reservation is expired date"
            ], 200);
        }

        $contract = Contract::where("user_id", auth()->user()->id)->where("estate_id", $request->estate_id)->first();
        $remaining_balance = intval($estate->price - $reserve->reservation_deposit);
        if ($user->balance < $remaining_balance) {
            return response([
                "isSuccess"       =>      false,
                "msg"             =>      "You haven't enough balance"
            ], 200);
        }
        if (!$contract) {
            $date = Carbon::now();
            // مدة العقد سنة
            $contract_record = Contract::create([
                "user_id"         =>      auth()->user()->id,
                "estate_id"       =>      $estate->id,
                "price"           =>      $estate->price,
                "expired_date"    =>      $date->addYear(),
                "extension"       =>      $request->extension
            ]);
            if (!$contract_record) {
                $contract_record->delete();
                return response([
                    "isSuccess"     =>      false,
                    "msg"           =>      "The contract isn't install"
                ], 200);
            }
            $estate->reserved = "no";
            $estate->rented = "yes";
            $estate->save();
            $reserve->expired_date = Carbon::now();
            $reserve->save();
            $user->balance -= $remaining_balance;
            $user->save();
            return response([
                "isSuccess"     =>      true,
                "msg"           =>      "Congratulations, the real-estate is yours now"
            ], 200);
        } else {
            $date = Carbon::parse($contract->expired_date);
            if ($date->isFuture()) {
                return response([
                    "isSuccess"     =>      false,
                    "msg"           =>      "Your contract isn't expired yet"
                ], 200);
            } else {
                $estate->rented = "no";
                $estate->save();
                if ($contract->extension == "no") {
                    return response([
                        "isSuccess"     =>      false,
                        "msg"           =>      "Your contract not accept extension"
                    ], 200);
                } else if ($contract->extension == "yes") {
                    return response([
                        "isSuccess"     =>      true,
                        "msg"           =>      "Click on the button to extension the contract"
                    ], 200);
                }
            }
        }
    }

    public function all_contracts(): HttpResponse
    {
        $contracts = Contract::where("user_id", auth()->user()->id)->get();
        if ($contracts->count() < 1) {
            return response([
                "isSuccess"     =>      false,
                "msg"           =>      "You haven't any contract yet"
            ], 200);
        }
        $data = [];
        foreach ($contracts as $contract) {
            $data[] = [
                "id"            =>      $contract->id,
                "owner_name"    =>      $contract->owner_name,
                "estate_id"     =>      $contract->estate_id,
                "balance"       =>      $contract->price,
                "expired_date"  =>      $contract->expired_date,
                "extension"     =>      $contract->extension
            ];
        }
        return response([
            "isSuccess"         =>      true,
            "contracts"         =>      $data
        ], 200);
    }

    // تسليم عقار لصاحبه قبل انتهاء مدة العقد
    public function early_delivery(string $id): HttpResponse
    {
        $contract = Contract::where("user_id", auth()->user()->id)->where("estate_id", $id)->first();
        if (!$contract) {
            return response([
                "isSuccess"         =>      false,
                "msg"               =>      "You haven't contract to this estate"
            ], 200);
        }
        $date = Carbon::parse($contract->expired_date);
        if (!$date->isFuture()) {
            return response([
                "isSuccess"         =>      false,
                "msg"               =>      "Your contract is expired already"
            ], 200);
        }
        $contract->estate->rented = "no";
        $contract->estate->save();
        $date = Carbon::now();
        $contract->expired_date = $date;
        $contract->save();
        return response([
            "isSuccess"             =>      true,
            "msg"                   =>      "Estate delivery is done"
        ], 200);
    }

    public function contract_extension(string $id): HttpResponse
    {
        $user = User::find(auth()->user()->id);
        // تبع العقد $id
        $contract = Contract::where("id", $id)->where("user_id", auth()->user()->id)->first();
        if (!$contract) {
            return response([
                "isSuccess"     =>      false,
                "msg"           =>      "This contract isn't found"
            ], 200);
        }
        $date = Carbon::parse($contract->expired_date);
        if (!$date->isFuture()) {
            return response([
                "isSuccess"     =>      false,
                "msg"           =>      "Your contract is expired"
            ], 200);
        }
        if ($contract->extension == "no") {
            return response([
                "isSuccess"     =>      false,
                "msg"           =>      "Your contract unable to extension"
            ], 200);
        }
        if ($user->balance < $contract->estate->price) {
            return response([
                "isSuccess"     =>      false,
                "msg"           =>      "You haven't balance enough"
            ], 200);
        }
        $user->balance -= $contract->estate->price;
        $user->save();
        $date = Carbon::parse($contract->expired_date);
        $contract->expired_date = $date->addYear();
        $contract->price = $contract->estate->price;
        $contract->save();
        $contract->estate->rented = "yes";
        $contract->estate->save();
        return response([
            "isSuccess"         =>      true,
            "msg"               =>      "The contract was extended for another year"
        ], 200);
    }

    public function show_balance(): HttpResponse
    {
        $user = User::find(auth()->user()->id);
        return response([
            "isSuccess"         =>      true,
            "current_balance"   =>      $user->balance
        ], 200);
    }
}
