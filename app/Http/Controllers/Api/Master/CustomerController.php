<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\Customer\StoreCustomerRequest;
use App\Http\Requests\Master\Customer\UpdateCustomerRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ApiResource;
use App\Model\Master\Address;
use App\Model\Master\Bank;
use App\Model\Master\ContactPerson;
use App\Model\Master\Customer;
use App\Model\Master\CustomerGroup;
use App\Model\Master\Email;
use App\Model\Master\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\Kpi\KpiTemplateImport;
use App\Imports\Kpi\TemplateCheckImport;
use App\Imports\Master\CustomerImport;
use App\Model\CloudStorage;
use App\Exports\CustomerExport;
use App\Model\Master\User;
use App\Model\Project\Project;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return \App\Http\Resources\ApiCollection
     */
    public function index(Request $request)
    {
        $customers = Customer::from(Customer::getTableName().' as '.Customer::$alias)->eloquentFilter($request);

        $customers = Customer::joins($customers, $request->get('join'));

        $user = tenant(auth()->user()->id);

        if ($request->get('group_id')) {
            $customers = $customers->leftJoin('groupables', function ($q) use ($request) {
                $q->on('groupables.groupable_id', '=', 'customers.id')
                    ->where('groupables.groupable_type', Customer::$morphName)
                    ->where('groupables.group_id', '=', $request->get('group_id'));
            });
        }

        if ($request->get('is_archived')) {
            $customers = $customers->whereNotNull('archived_at');
        } else {
            $customers = $customers->whereNull('archived_at');
        }

        $customers->whereIn('customer.branch_id', $user->branches->pluck('id'));

        $customers = pagination($customers, $request->get('limit'));

        return new ApiCollection($customers);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \App\Http\Requests\Master\Customer\StoreCustomerRequest $request
     *
     * @return \App\Http\Resources\ApiResource
     */
    public function store(StoreCustomerRequest $request)
    {
        DB::connection('tenant')->beginTransaction();

        $user = tenant(auth()->user()->id);
        $defaultBranch = null;
        foreach($user->branches as $branch) {
            if ($branch->pivot->is_default == true) {
                $defaultBranch = $branch->id;
                break;
            }
        }
        
        $customer = new Customer;
        $customer->fill($request->all());
        $customer->branch_id = $defaultBranch;
        $customer->save();

        if ($request->has('groups')) {
            foreach ($request->get('groups') as $arrGroups) {
                if (! empty($arrGroups['name'])) {
                    $group = CustomerGroup::where('name', $arrGroups['name'])->first();
                    if (! $group) {
                        $group = new CustomerGroup;
                        $group->name = $arrGroups['name'];
                        $group->save();
                    }
                }
            }
            $groups = Arr::pluck($request->get('groups'), 'id');
            $groups = array_filter($groups, 'strlen');
            $customer->groups()->sync($groups);
        }

        Address::saveFromRelation($customer, $request->get('addresses'));
        Phone::saveFromRelation($customer, $request->get('phones'));
        Email::saveFromRelation($customer, $request->get('emails'));
        ContactPerson::saveFromRelation($customer, $request->get('contacts'));
        Bank::saveFromRelation($customer, $request->get('banks'));

        DB::connection('tenant')->commit();

        return new ApiResource($customer);
    }

    public function importCustomer(Request $request)
    {
        $request->validate([
            'code' => 'required',
            'name' => 'required',
            'address' => 'required',
            'phone' => 'required',
            'email' => 'required',
            'file' => 'required|mimes:xlsx,xls,csv|max:1024',
        ]);

        $result = Excel::import(new CustomerImport, request()->file('file'));

        return response()->json([
            'message' => 'success',
        ], 200);
    }

    public function exportCustomer(Request $request)
    {
        $tenant = strtolower($request->header('Tenant'));
        $key = Str::random(16);
        $fileName = strtoupper($tenant)
          .' - Customer Export - ';
        $fileExt = 'xlsx';
        $path = 'tmp/'.$tenant.'/'.$key.'.'.$fileExt;

        $result = Excel::store(new CustomerExport($tenant), $path, env('STORAGE_DISK'));

        if (! $result) {
            return response()->json([
                'message' => 'Failed to export',
            ], 422);
        }

        $cloudStorage = new CloudStorage();
        $cloudStorage->file_name = $fileName;
        $cloudStorage->file_ext = $fileExt;
        $cloudStorage->feature = 'Customer export';
        $cloudStorage->key = $key;
        $cloudStorage->path = $path;
        $cloudStorage->disk = env('STORAGE_DISK');
        $cloudStorage->project_id = Project::where('code', strtolower($tenant))->first()->id;
        $cloudStorage->owner_id = 1;
        $cloudStorage->expired_at = Carbon::now()->addDay(1);
        $cloudStorage->download_url = env('API_URL').'/download?key='.$key;
        
        $cloudStorage->save();
        
        return response()->json([
            'data' => [
                'url' => $cloudStorage->download_url,
            ],
        ], 200);
        // return Excel::download(new CustomerExport, 'customer-export.xlsx');
    }


    /**
     * Display the specified resource.
     *
     * @param Request $request
     * @param  int $id
     * @return \App\Http\Resources\ApiResource
     */
    public function show(Request $request, $id)
    {
        $customer = Customer::from(Customer::getTableName().' as '.Customer::$alias)->eloquentFilter($request);

        $customer = Customer::joins($customer, $request->get('join'));

        $customer = $customer->where(Customer::$alias.'.id', $id)->first();

        if ($request->get('total_payable')) {
            $customer->total_payable = $customer->totalAccountPayable();
        }
        if ($request->get('total_receivable')) {
            $customer->total_receivable = $customer->totalAccountReceivable();
        }

        return new ApiResource($customer);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \App\Http\Requests\Master\Customer\UpdateCustomerRequest $request
     * @param $id
     *
     * @return \App\Http\Resources\ApiResource
     */
    public function update(UpdateCustomerRequest $request, $id)
    {
        DB::connection('tenant')->beginTransaction();

        $customer = Customer::findOrFail($id);
        $customer->fill($request->all());
        $customer->save();

        if ($request->has('groups')) {
            foreach ($request->get('groups') as $arrGroups) {
                if (! empty($arrGroups['name'])) {
                    $group = CustomerGroup::where('name', $arrGroups['name'])->first();
                    if (! $group) {
                        $group = new CustomerGroup;
                        $group->name = $arrGroups['name'];
                        $group->save();
                    }
                }
            }
            $groups = Arr::pluck($request->get('groups'), 'id');
            $groups = array_filter($groups, 'strlen');
            $customer->groups()->sync($groups);
        }

        Address::saveFromRelation($customer, $request->get('addresses'));
        Phone::saveFromRelation($customer, $request->get('phones'));
        Email::saveFromRelation($customer, $request->get('emails'));
        ContactPerson::saveFromRelation($customer, $request->get('contacts'));
        Bank::saveFromRelation($customer, $request->get('banks'));

        DB::connection('tenant')->commit();

        return new ApiResource($customer);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $customer = Customer::findOrFail($id);
        $customer->delete();

        return response()->json([], 204);
    }

    /**
     * delete the specified resource from storage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkDelete(Request $request)
    {
        $customers = $request->get('customers');
        foreach ($customers as $customer) {
            $customer = Customer::findOrFail($customer['id']);
            $customer->delete();
        }

        return response()->json([], 204);
    }

    /**
     * Archive the specified resource from storage.
     *
     * @param int $id
     * @return ApiResource
     */
    public function archive($id)
    {
        $customer = Customer::findOrFail($id);
        $customer->archive();

        return new ApiResource($customer);
    }

    /**
     * Archive the specified resource from storage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkArchive(Request $request)
    {
        $customers = $request->get('customers');
        foreach ($customers as $customer) {
            $customer = Customer::findOrFail($customer['id']);
            $customer->archive();
        }

        return response()->json([], 200);
    }

    /**
     * Activate the specified resource from storage.
     *
     * @param int $id
     * @return ApiResource
     */
    public function activate($id)
    {
        $customer = Customer::findOrFail($id);
        $customer->activate();

        return new ApiResource($customer);
    }

    /**
     * Archive the specified resource from storage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkActivate(Request $request)
    {
        $customers = $request->get('customers');
        foreach ($customers as $customer) {
            $customer = Customer::findOrFail($customer['id']);
            $customer->activate();
        }

        return response()->json([], 200);
    }
}
