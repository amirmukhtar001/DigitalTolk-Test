<?php

namespace DTApi\Repository;

use DTApi\Models\Company;
use DTApi\Models\Department;
use DTApi\Models\Type;
use DTApi\Models\UsersBlacklist;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use DTApi\Models\User;
use DTApi\Models\Town;
use DTApi\Models\UserMeta;
use DTApi\Models\UserTowns;
use DTApi\Events\JobWasCreated;
use DTApi\Models\UserLanguages;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\FirePHPHandler;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class UserRepository extends BaseRepository
{

    protected $model;
    protected $logger;

    /**
     * @param User $model
     */
    function __construct(User $model)
    {
        parent::__construct($model);
//        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function createOrUpdate($id = null, $request)
    {
        $model = $id ? User::findOrFail($id) : new User;

        $model->fill([
            'user_type' => $request['role'],
            'name' => $request['name'],
            'company_id' => $request['company_id'] ?: 0,
            'department_id' => $request['department_id'] ?: 0,
            'email' => $request['email'],
            'dob_or_orgid' => $request['dob_or_orgid'],
            'phone' => $request['phone'],
            'mobile' => $request['mobile'],
        ]);

        if (!$id || $request['password']) {
            $model->password = bcrypt($request['password']);
        }

        $model->detachAllRoles();
        $model->save();
        $model->attachRole($request['role']);

        if ($request['role'] == env('CUSTOMER_ROLE_ID')) {
            $this->handleCustomer($model, $request);
        }

        if ($request['role'] == env('TRANSLATOR_ROLE_ID')) {
            $this->handleTranslator($model, $request);
        }

        // Handle user towns
        if (!empty($request['new_towns'])) {
            $this->createNewTown($request['new_towns']);
        }

        if (!empty($request['user_towns_projects'])) {
            $this->updateUserTowns($model->id, $request['user_towns_projects']);
        }

        // Update user status
        $this->updateUserStatus($model, $request['status']);

        return $model;
    }

    private function handleCustomer($model, $request)
    {
        if ($request['consumer_type'] == 'paid' && empty($request['company_id'])) {
            $type = Type::where('code', 'paid')->first();
            $company = Company::create([
                'name' => $request['name'],
                'type_id' => $type->id,
                'additional_info' => "Created automatically for user {$model->id}",
            ]);
            $department = Department::create([
                'name' => $request['name'],
                'company_id' => $company->id,
                'additional_info' => "Created automatically for user {$model->id}",
            ]);

            $model->update(['company_id' => $company->id, 'department_id' => $department->id]);
        }

        $user_meta = UserMeta::updateOrCreate(
            ['user_id' => $model->id],
            array_merge($request->only([
                'consumer_type', 'customer_type', 'username', 'post_code', 'address',
                'city', 'town', 'country', 'additional_info', 'cost_place', 'fee',
                'time_to_charge', 'time_to_pay', 'charge_ob', 'customer_id',
                'charge_km', 'maximum_km'
            ]), [
                'reference' => isset($request['reference']) && $request['reference'] == 'yes' ? '1' : '0',
            ])
        );

        $this->updateBlacklist($model->id, $request['translator_ex']);
    }

    private function handleTranslator($model, $request)
    {
        $user_meta = UserMeta::updateOrCreate(
            ['user_id' => $model->id],
            $request->only([
                'translator_type', 'worked_for', 'gender', 'translator_level',
                'additional_info', 'post_code', 'address', 'address_2', 'town'
            ])
        );

        if ($request['worked_for'] == 'yes') {
            $user_meta->update(['organization_number' => $request['organization_number']]);
        }

        if (!empty($request['user_language'])) {
            $this->updateUserLanguages($model->id, $request['user_language']);
        }
    }

    private function updateBlacklist($user_id, $translator_ex)
    {
        $existingBlacklist = UsersBlacklist::where('user_id', $user_id)->pluck('translator_id')->toArray();
        $newBlacklist = array_diff($translator_ex ?? [], $existingBlacklist);

        foreach ($newBlacklist as $translatorId) {
            UsersBlacklist::create(['user_id' => $user_id, 'translator_id' => $translatorId]);
        }

        $toDelete = array_diff($existingBlacklist, $translator_ex ?? []);
        UsersBlacklist::where('user_id', $user_id)->whereIn('translator_id', $toDelete)->delete();
    }

    private function updateUserLanguages($user_id, $user_languages)
    {
        UserLanguages::where('user_id', $user_id)->delete();

        foreach ($user_languages as $langId) {
            UserLanguages::firstOrCreate(['user_id' => $user_id, 'lang_id' => $langId]);
        }
    }

    private function createNewTown($town_name)
    {
        Town::create(['townname' => $town_name]);
    }

    private function updateUserTowns($user_id, $user_towns)
    {
        DB::table('user_towns')->where('user_id', $user_id)->delete();

        foreach ($user_towns as $townId) {
            UserTowns::firstOrCreate(['user_id' => $user_id, 'town_id' => $townId]);
        }
    }

    private function updateUserStatus($model, $status)
    {
        if ($status == '1' && $model->status != '1') {
            $this->enable($model->id);
        } elseif ($status == '0' && $model->status != '0') {
            $this->disable($model->id);
        }
    }


    public function enable($id)
    {
        $user = User::findOrFail($id);
        $user->status = '1';
        $user->save();

    }

    public function disable($id)
    {
        $user = User::findOrFail($id);
        $user->status = '0';
        $user->save();

    }

    public function getTranslators()
    {
        return User::where('user_type', 2)->get();
    }

}
