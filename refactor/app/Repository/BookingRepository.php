<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{
    const IMMEDIATE_TIME = 5;

    const STATUS_SUCCESS = 'success';
    const STATUS_FAIL = 'fail';

    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->initializeLogger();
    }

    /**
     * Initialize the logger for the repository.
     */
    private function initializeLogger()
    {
        $this->logger = new Logger('admin_logger');

        $logPath = storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log');
        $this->logger->pushHandler(new StreamHandler($logPath, Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $userType = '';
        $emergencyJobs = [];
        $normalJobs = [];
        $jobs = [];

        if ($cuser) {
            if ($cuser->is('customer')) {
                $userType = 'customer';
                $jobs = $this->getCustomerJobs($cuser);
            } elseif ($cuser->is('translator')) {
                $userType = 'translator';
                $jobs = $this->getTranslatorJobs($cuser->id);
            }
        }

        if (count($jobs) > 0) {
            foreach ($jobs as $jobitem) {
                $targetArray = ($jobitem->immediate == 'yes') ? $emergencyJobs : $normalJobs;
                $targetArray[] = $jobitem;
            }

            $normalJobs = collect($normalJobs)->each(function ($item) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $cuser, 'usertype' => $userType];
    }



    /**
     * Get user's job history.
     *
     * @param int     $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page');
        $pageNumber = isset($page) ? $page : 1;

        $cuser = User::find($user_id);

        if ($cuser) {
            if ($cuser->is('customer')) {
                return $this->getCustomerJobHistory($cuser, $pageNumber);
            } elseif ($cuser->is('translator')) {
                // Assuming getTranslatorJobHistory function is similar to getCustomerJobHistory
                return $this->getTranslatorJobHistory($cuser, $pageNumber);
            }
        }

        return [];
    }


    /**
     * Get customer jobs based on status and order.
     *
     * @param User $cuser
     * @param array $statusArray
     * @param string $order
     * @param int $limit
     * @param int|null $pageNumber
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Pagination\LengthAwarePaginator
     */
    private function getCustomerJobsByStatus(User $cuser, array $statusArray, string $order, int $limit, int $pageNumber = null)
    {
        $query = $cuser->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
            ->whereIn('status', $statusArray)
            ->orderBy('due', $order);

        if ($pageNumber !== null) {
            return $query->paginate($limit, ['*'], 'page', $pageNumber);
        }

        return $query->limit($limit)->get();
    }


    /**
     * Get customer jobs.
     *
     * @param User $cuser
     * @param int|null $pageNumber
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Pagination\LengthAwarePaginator
     */
    private function getCustomerJobs(User $cuser, int $pageNumber = null)
    {
        $statusArray = ['pending', 'assigned', 'started']; //These should be constans defined in retreiving model 
        $order = 'asc';
        $limit = PHP_INT_MAX; // Retrieve all records

        return $this->getCustomerJobsByStatus($cuser, $statusArray, $order, $limit, $pageNumber);
    }

    /**
     * Get customer job history.
     *
     * @param User $cuser
     * @param int|null $pageNumber
     * @return array
     */
    private function getCustomerJobHistory(User $cuser, int $pageNumber = null)
    {
        $statusArray = ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout']; //These should be constans defined in retreiving model 
        $order = 'desc';
        $limit = 15;

        $jobs = $this->getCustomerJobsByStatus($cuser, $statusArray, $order, $limit, $pageNumber);

        return [
            'emergencyJobs' => [],
            'normalJobs' => [],
            'jobs' => $jobs,
            'cuser' => $cuser,
            'usertype' => 'customer',
            'numpages' => ($pageNumber !== null) ? $jobs->lastPage() : 0,
            'pagenum' => $pageNumber ?? 0
        ];
    }



    /**
     * Get translator jobs.
     *
     * @param int $translatorId
     * @return \Illuminate\Support\Collection
     */
    private function getTranslatorJobs($translatorId)
    {
        $jobs = Job::getTranslatorJobs($translatorId, 'new');
        return $jobs->pluck('jobs')->all();
    }


    /**
     * Get translator job history.
     *
     * @param User $cuser
     * @param int  $pageNumber
     * @return array
     */
    private function getTranslatorJobHistory(User $cuser, $pageNumber)
    {
        $jobsIds = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pageNumber);
        $totalJobs = $jobsIds->total();
        $numPages = ceil($totalJobs / 15);

        return [
            'emergencyJobs' => [],
            'normalJobs' => $jobsIds,
            'jobs' => $jobsIds,
            'cuser' => $cuser,
            'usertype' => 'translator',
            'numpages' => $numPages,
            'pagenum' => $pageNumber
        ];
    }

    /**
     * Set fail response
     *
     * @param string $fieldName
     * @param string $message
     * @return array
     */
    private function setFailResponse($fieldName, $message)
    {
        return ['status' => self::STATUS_FAIL, 'message' => $message, 'field_name' => $fieldName];
    }

    /**
     * Validate booking data
     *
     * @param array $data
     * @return array|null
     */
    private function validateBookingData($data)
    {
        if (!isset($data['from_language_id'])) {
            return $this->setFailResponse("from_language_id", "Du måste fylla in alla fält");
        }

        if ($data['immediate'] == 'no') {
            if (isset($data['due_date']) && $data['due_date'] == '') {
                return $this->setFailResponse("due_date", "Du måste fylla in alla fält");
            }

            if (isset($data['due_time']) && $data['due_time'] == '') {
                return $this->setFailResponse("due_time", "Du måste fylla in alla fält");
            }

            if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                return $this->setFailResponse("customer_phone_type", "Du måste göra ett val här");
            }

            if (isset($data['duration']) && $data['duration'] == '') {
                return $this->setFailResponse("duration", "Du måste fylla in alla fält");
            }
        } else {
            if (isset($data['duration']) && $data['duration'] == '') {
                return $this->setFailResponse("duration", "Du måste fylla in alla fält");
            }
        }

        return null;
    }
    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {

        $consumer_type = $user->userMeta->consumer_type;

        if ($user->user_type != config('app.customer_role_id')) {
            return ['status' => self::STATUS_FAIL, 'message' => "Translator can not create booking"];
        }

        $cuser = $user;

        $response = $this->validateBookingData($data);

        if ($response !== null) {
            return $response;
        }


        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';

        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
        $response['customer_physical_type'] = $data['customer_physical_type'];


        if ($data['immediate'] == 'yes') {
            $due_carbon = Carbon::now()->addMinute(self::IMMEDIATE_TIME);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);

            if ($due_carbon->isPast()) {
                return [
                    'status' => 'fail',
                    'message' => "Can't create booking in the past",
                ];
            }
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
        }

        // Set gender based on job_for array
        if (in_array('male', $data['job_for'])) {
            $data['gender'] = 'male';
        } else if (in_array('female', $data['job_for'])) {
            $data['gender'] = 'female';
        }

        // Set certified value based on job_for array
        if (in_array('normal', $data['job_for'])) {
            $data['certified'] = 'normal';
        } else if (in_array('certified', $data['job_for'])) {
            $data['certified'] = 'yes';
        } else if (in_array('certified_in_law', $data['job_for'])) {
            $data['certified'] = 'law';
        } else if (in_array('certified_in_helth', $data['job_for'])) {
            $data['certified'] = 'health';
        }

        // Additional conditions for certified value
        if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
            $data['certified'] = 'both';
        } else if (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
            $data['certified'] = 'n_law';
        } else if (in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
            $data['certified'] = 'n_health';
        }

        // Set job_type based on consumer_type
        if ($consumer_type == 'rwsconsumer') {
            $data['job_type'] = 'rws';
        } elseif ($consumer_type == 'ngo') {
            $data['job_type'] = 'unpaid';
        } elseif ($consumer_type == 'paid') {
            $data['job_type'] = 'paid';
        }


        // Set b_created_at
        $data['b_created_at'] = date('Y-m-d H:i:s');

        // Set will_expire_at if due is set
        if (isset($due)) {
            $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
        }

        // Set by_admin default value
        $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

        // Create a job
        $job = $cuser->jobs()->create($data);

        // Set response values
        $response['status'] = 'success';
        $response['id'] = $job->id;

        // Clear job_for array
        $data['job_for'] = [];

        // Map gender to job_for array
        if ($job->gender != null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        // Map certified to job_for array
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } elseif ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        // Set additional values
        $data['customer_town'] = $cuser->userMeta->city;
        $data['customer_type'] = $cuser->userMeta->customer_type;


        return $response;
    }


    /**
     * Update job details based on provided data.
     *
     * @param Job $job
     * @param array $data
     * @return void
     */
    private function updateJobDetails(Job $job, array $data)
    {
        try {
            $job->user_email = $data['user_email'] ?? $job->user_email;
            $job->reference = $data['reference'] ?? '';

            if (isset($data['address'])) {
                $userMeta = $job->user->userMeta;

                $job->address = $data['address'] ?: $userMeta->address;
                $job->instructions = $data['instructions'] ?: $userMeta->instructions;
                $job->town = $data['town'] ?: $userMeta->city;
            }

            $job->save();
        } catch (\Exception $e) {
            throw new \Exception('Error updating job details');
        }
    }

    /**
     * Send job created email to the user.
     *
     * @param User $user
     * @param Job $job
     * @return void
     */
    private function sendJobCreatedEmail(User $user, Job $job)
    {
        try {
            $email = $job->user_email ?: $user->email;
            $name = $user->name;
            $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

            $send_data = ['user' => $user, 'job' => $job];
            $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);
        } catch (\Exception $e) {
            // Log or handle the exception
            // Example: Log::error('Error sending job created email: ' . $e->getMessage());
            throw new \Exception('Error sending job created email');
        }
    }
    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $userType = $data['user_type'];
        $job = Job::findOrFail($data['user_email_job_id']);

        try {
            $this->updateJobDetails($job, $data);

            $user = $job->user()->first();
            $this->sendJobCreatedEmail($user, $job);


            $response = [
                'type' => $userType,
                'job' => $job,
                'status' => self::STATUS_SUCCESS,
            ];

            Event::fire(new JobWasCreated($job, $this->jobToData($job), '*'));

            return $response;
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param Job $job
     * @return array
     */

    private function getJobForArray(Job $job)
    {
        $jobFor = [];

        if ($job->gender !== null) {
            $jobFor[] = ($job->gender === 'male') ? 'Man' : 'Kvinna';
        }

        if ($job->certified !== null) {
            switch ($job->certified) {
                case 'both':
                    $jobFor[] = 'Godkänd tolk';
                    $jobFor[] = 'Auktoriserad';
                    break;
                case 'yes':
                    $jobFor[] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $jobFor[] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $jobFor[] = 'Rätttstolk';
                    break;
                default:
                    $jobFor[] = $job->certified;
                    break;
            }
        }

        return $jobFor;
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {

        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
        ];

        [$due_date, $due_time] = explode(" ", $job->due);
        $data['due_date'] = $due_date ?? '';
        $data['due_time'] = $due_time ?? '';

        $data['job_for'] = $this->getJobForArray($job);

        return $data;
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = [])
    {
        $completedDate = now();
        $jobId = $post_data["job_id"];
        $job = Job::with('translatorJobRel')->find($jobId);

        $dueDate = date_create($job->due);
        $diff = date_diff(now(), $dueDate);
        $interval = $diff->format('%h:%i:%s');

        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $sessionTime = implode(' ', explode(':', $interval));

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'faktura',
        ];

        // Sending email to the user
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        // Updating job details
        $job->update([
            'end_at' => $completedDate,
            'status' => 'completed',
            'session_time' => $interval,
        ]);

        // Handling translator job relationship
        $translatorJobRel = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $translatorJobRel->user_id : $job->user_id));

        $translatorUser = $translatorJobRel->user()->first();
        $translatorEmail = $translatorUser->email;
        $translatorName = $translatorUser->name;

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user' => $translatorUser,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'lön',
        ];

        // Sending email to the translator
        $mailer->send($translatorEmail, $translatorName, $subject, 'emails.session-ended', $data);

        // Updating translator job relationship
        $translatorJobRel->update([
            'completed_at' => $completedDate,
            'completed_by' => $post_data['userid'],
        ]);
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $userMeta = UserMeta::where('user_id', $user_id)->first();
        $translatorType = $userMeta->translator_type;

        $jobType = $translatorType == 'professional' ? 'paid' : ($translatorType == 'rwstranslator' ? 'rws' : 'unpaid');

        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;

        $jobIds = Job::getJobs($user_id, $jobType, 'pending', $languages, $gender, $translatorLevel);

        foreach ($jobIds as $key => $jobId) {
            $job = Job::find($jobId->id);
            $jobUserId = $job->user_id;

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') &&
                $job->customer_physical_type == 'yes' &&
                !Job::checkTowns($jobUserId, $user_id)
            ) {
                unset($jobIds[$key]);
            }
        }

        return TeHelper::convertJobIdsInObjs($jobIds);
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::where('user_type', '2')
            ->where('status', '1')
            ->where('id', '!=', $exclude_user_id)
            ->get();

        $translatorArray = [];
        $delayPayTranslatorArray = [];

        foreach ($users as $user) {
            if (!$this->isNeedToSendPush($user->id) || $data['immediate'] == 'yes' && TeHelper::getUsermeta($user->id, 'not_get_emergency') == 'yes') {
                continue;
            }

            $jobs = $this->getPotentialJobIdsWithUserId($user->id);

            foreach ($jobs as $potentialJob) {
                if ($job->id == $potentialJob->id) {
                    $userId = $user->id;
                    $jobForTranslator = Job::assignedToPaticularTranslator($userId, $potentialJob->id);

                    if ($jobForTranslator == 'SpecificJob') {
                        $jobChecker = Job::checkParticularJob($userId, $potentialJob);

                        if ($jobChecker != 'userCanNotAcceptJob') {
                            $translatorArray[] = $user;
                            if ($this->isNeedToDelayPush($user->id)) {
                                $delayPayTranslatorArray[] = $user;
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        $msgContents = ($data['immediate'] == 'no')
            ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due']
            : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';

        $msgText = ["en" => $msgContents];

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delayPayTranslatorArray, $msgText, $data]);

        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);
        $this->sendPushNotificationToSpecificUsers($delayPayTranslatorArray, $job->id, $data, $msgText, true);
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // analyse weather it's phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') return false;
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
        if (config('app.env') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );
        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        $job_type = $job->job_type;

        if ($job_type == 'paid')
            $translator_type = 'professional';
        else if ($job_type == 'rws')
            $translator_type = 'rwstranslator';
        else if ($job_type == 'unpaid')
            $translator_type = 'volunteer';

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];
        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $translator_level[] = 'Certified with specialisation in law';
            } elseif ($job->certified == 'health' || $job->certified == 'n_health') {
                $translator_level[] = 'Certified with specialisation in health care';
            } else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            } elseif ($job->certified == null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);

        return $users;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator))
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $log_data = [];

        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $oldStatus = $job->status;
        $statusChanged = false;
        if ($oldStatus != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $oldStatus,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $jobData = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $jobData, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();

        // Return true as the default case
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
        }
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
            // If admin comments are empty and status is 'timedout', return false
            return false;
        }

        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }

        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }

        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $dueExplode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $dueExplode[1] . ' på ' . $dueExplode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $dueExplode[1] . ' på ' . $dueExplode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        $statusOptions = ['withdrawbefore24', 'withdrawafter24', 'timedout'];

        if (in_array($data['status'], $statusOptions)) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();
                $email = $job->user_email ?: $user->email;
                $name = $user->name;

                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

                if ($translator) {
                    $translatorEmail = $translator->user->email;
                    $translatorName = $translator->user->name;

                    $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                    $dataEmail = [
                        'user' => $translator,
                        'job'  => $job
                    ];
                    $this->mailer->send($translatorEmail, $translatorName, $subject, 'emails.job-cancel-translator', $dataEmail);
                }
            }

            $job->save();
            return true;
        }

        return false;
    }


    /**
     * @param $currentTranslator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($currentTranslator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($currentTranslator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($currentTranslator) && ((isset($data['translator']) && $currentTranslator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $newTranslator = $currentTranslator->toArray();
                $newTranslator['user_id'] = $data['translator'];
                unset($newTranslator['id']);
                $newTranslator = Translator::create($newTranslator);
                $currentTranslator->cancel_at = Carbon::now();
                $currentTranslator->save();
                $log_data[] = [
                    'old_translator' => $currentTranslator->user->email,
                    'new_translator' => $newTranslator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($currentTranslator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $newTranslator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $newTranslator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $newTranslator, 'log_data' => $log_data];
        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    /**
     * @param $job
     * @param $currentTranslator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $currentTranslator, $new_translator)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($currentTranslator) {
            $user = $currentTranslator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * making userTags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $userTags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $userTags .= ',{"operator": "OR"},';
            }
            $userTags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $userTags .= ']';
        return $userTags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                }
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($cuser);
            $response = array();
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;
    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $job = Job::findOrFail($job_id);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                } else {
                    $email = $user->email;
                    $name = $user->name;
                }
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = array($user);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    /**
     * Cancel a job through AJAX.
     *
     * @param array $data - The data related to the cancellation.
     * @param User $user - The user initiating the cancellation.
     *
     * @return array - The response indicating the status of the cancellation.
     */
    public function cancelJobAjax($data, $user)
    {
        $response = [];

        // @todo: Add 24hrs logging here.

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $this->handleCustomerCancellation($job, $translator);
        } else {
            $this->handleTranslatorCancellation($job, $translator, $response);
        }

        return $response;
    }

    /**
     * Handle the cancellation initiated by a customer.
     *
     * @param Job $job - The job to be canceled.
     * @param mixed $translator - The translator associated with the job.
     *
     * @return void
     */
    private function handleCustomerCancellation(Job $job, $translator)
    {
        $job->withdraw_at = Carbon::now();
        $job->status = $job->withdraw_at->diffInHours($job->due) >= 24 ? 'withdrawbefore24' : 'withdrawafter24';
        $job->save();
        Event::fire(new JobWasCanceled($job));
        $response['status'] = 'success';
        $response['jobstatus'] = 'success';

        if ($translator && $this->isNeedToSendPush($translator->id)) {
            $data = [
                'notification_type' => 'job_cancelled',
                'msg_text' => [
                    'en' => 'Kunden har avbokat bokningen för ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ]
            ];
            $this->sendPushNotificationToSpecificUsers([$translator], $job->id, $data, $data['msg_text'], $this->isNeedToDelayPush($translator->id));
        }
    }

    /**
     * Handle the cancellation initiated by a translator.
     *
     * @param Job $job - The job to be canceled.
     * @param mixed $translator - The translator associated with the job.
     * @param array $response - The response array to be modified based on the cancellation handling.
     *
     * @return void
     */
    private function handleTranslatorCancellation(Job $job, $translator, &$response)
    {
        if ($job->due->diffInHours(Carbon::now()) > 24) {
            // Handling cancellation that is more than 24 hours before the job
            $customer = $job->user()->first();
            if ($customer && $this->isNeedToSendPush($customer->id)) {
                $data = [
                    'notification_type' => 'job_cancelled',
                    'msg_text' => [
                        'en' => 'Er ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    ]
                ];
                $this->sendPushNotificationToSpecificUsers([$customer], $job->id, $data, $data['msg_text'], $this->isNeedToDelayPush($customer->id));
            }

            $job->status = 'pending';
            $job->created_at = now();
            $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
            $job->save();
            Job::deleteTranslatorJobRel($translator->id, $job->id);

            $data = $this->jobToData($job);
            $this->sendNotificationTranslator($job, $data, $translator->id);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
        }
    }


    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuserMeta = $cuser->userMeta;
        $jobType = 'unpaid';
        $translatorType = $cuserMeta->translator_type;
        if ($translatorType == 'professional')
            $jobType = 'paid';   /*show all jobs for professionals.*/
        else if ($translatorType == 'rwstranslator')
            $jobType = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translatorType == 'volunteer')
            $jobType = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuserMeta->gender;
        $translatorLevel = $cuserMeta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = Job::getJobs($cuser->id, $jobType, 'pending', $userlanguage, $gender, $translatorLevel);
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                    unset($job_ids[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        return $job_ids;
    }

    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if ($job_detail->status != 'started')
            return ['status' => 'success'];

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }


    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == config('app.superadmin_role_id')) {
            $allJobs = Job::query();

            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                if (is_array($requestdata['id']))
                    $allJobs->whereIn('id', $requestdata['id']);
                else
                    $allJobs->where('id', $requestdata['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }
            if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
            }
            if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
            }
            if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }
            if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }

            if (isset($requestdata['physical'])) {
                $allJobs->where('customer_physical_type', $requestdata['physical']);
                $allJobs->where('ignore_physical', 0);
            }

            if (isset($requestdata['phone'])) {
                $allJobs->where('customer_phone_type', $requestdata['phone']);
                if (isset($requestdata['physical']))
                    $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($requestdata['flagged'])) {
                $allJobs->where('flagged', $requestdata['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if (isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
                $allJobs = $allJobs->count();

                return ['count' => $allJobs];
            }

            if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function ($q) use ($requestdata) {
                    $q->where('consumer_type', $requestdata['consumer_type']);
                });
            }

            if (isset($requestdata['booking_type'])) {
                if ($requestdata['booking_type'] == 'physical')
                    $allJobs->where('customer_physical_type', 'yes');
                if ($requestdata['booking_type'] == 'phone')
                    $allJobs->where('customer_phone_type', 'yes');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);
        } else {

            $allJobs = Job::query();

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                $allJobs->where('id', $requestdata['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            if ($consumer_type == 'RWS') {
                $allJobs->where('job_type', '=', 'rws');
            } else {
                $allJobs->where('job_type', '=', 'unpaid');
            }
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }
            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', '=', $user->id);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);
        }
        return $allJobs;
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs[$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $allCustomers = DB::table('users')->where('user_type', '1')->lists('email');
        $allTranslators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();


        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $allCustomers, 'all_translators' => $allTranslators, 'requestdata' => $requestdata];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return [self::STATUS_SUCCESS, 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return [self::STATUS_SUCCESS, 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return [self::STATUS_SUCCESS, 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid)->toArray();

        $data = [
            'created_at' => Carbon::now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], Carbon::now()),
            'updated_at' => Carbon::now(),
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => Carbon::now(),
        ];

        $datareopen = [
            'status' => 'pending',
            'created_at' => Carbon::now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], Carbon::now()),
        ];

        if ($job['status'] != 'timedout') {
            Job::where('id', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job = [
                'status' => 'pending',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'will_expire_at' => TeHelper::willExpireAt($job['due'], Carbon::now()),
                'updated_at' => Carbon::now(),
                'cust_16_hour_email' => 0,
                'cust_48_hour_email' => 0,
                'admin_comments' => 'This booking is a reopening of booking #' . $jobid,
            ];
            $newJob = Job::create($job);
            $new_jobid = $newJob->id;
        }

        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);

        $this->sendNotificationByAdminCancelJob($new_jobid);

        return ["Tolk cancelled!"];
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }

    /**
     * Update distance information.
     *
     * @param int $jobid
     * @param array $data
     * @return void
     */
    public function updateDistance($jobid, $data)
    {
        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';

        if ($time || $distance) {
            Distance::where('job_id', $jobid)->update(['distance' => $distance, 'time' => $time]);
        }
    }

    /**
     * Update job information.
     *
     * @param int $jobid
     * @param array $data
     * @return void
     */
    public function updateJobInformation($jobid, $data)
    {
        $flagged = $data['flagged'] == 'true' ? 'yes' : 'no';
        $manuallyHandled = $data['manually_handled'] == 'true' ? 'yes' : 'no';
        $byAdmin = $data['by_admin'] == 'true' ? 'yes' : 'no';

        $admincomment = $data['admincomment'] ?? '';

        if ($admincomment || $data['session_time'] || $flagged || $manuallyHandled || $byAdmin) {
            Job::where('id', $jobid)->update([
                'admin_comments' => $admincomment,
                'flagged' => $flagged,
                'session_time' => $data['session_time'],
                'manually_handled' => $manuallyHandled,
                'by_admin' => $byAdmin
            ]);
        }
    }
}
