<?php

namespace DTApi\Http\Controllers;

use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $user = $request->__authenticatedUser;

        if ($user_id = $request->get('user_id')) {
            $response = $this->repository->getUsersJobs($user_id);
        } elseif ($user->user_type == config('app.admin_role_id') || $user->user_type == config('app.superadmin_role_id')) {
            $response = $this->repository->getAll($request);
        } else {
            // Handle the case where neither condition is met, possibly set $response to a default value.
            $response = null;
        }

        return response()->json($response);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        //This ensures that clients receive a proper response when the requested job does not exist.
        if (!$job) {
            return response()->json(['error' => 'Job not found.'], 404);
        }

        return response()->json($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $data = $request->all();

        $response = $this->repository->store($request->__authenticatedUser, $data);

        return response($response);
    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->all();
        $cuser = $request->__authenticatedUser;
        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);

        return response($response);
    }

    /**
     * Store an immediate job email.
     *
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->storeJobEmail($data);
        return response($response);
    }

    /**
     * Get the job history for a user.
     *
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        $user_id = $request->get('user_id');

        if ($user_id) {
            $response = $this->repository->getUsersJobsHistory($user_id, $request);
            return response($response);
        }

        return null;
    }

    /**
     * Accept a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $response = $this->repository->acceptJob($data, $user);
        return response($response);
    }

    /**
     * Accept a job with a specific ID.
     *
     * @param Request $request
     * @return mixed
     */
    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;
        $response = $this->repository->acceptJobWithId($data, $user);
        return response($response);
    }

    /**
     * Cancel a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $response = $this->repository->cancelJobAjax($data, $user);
        return response($response);
    }

    /**
     * End a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->endJob($data);
        return response($response);
    }

    /**
     * Mark a job as customer not called.
     *
     * @param Request $request
     * @return mixed
     */
    public function customerNotCall(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->customerNotCall($data);
        return response($response);
    }

    /**
     * Get potential jobs for a user.
     *
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $response = $this->repository->getPotentialJobs($user);
        return response($response);
    }

    /**
     * Update distance and job information for a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->all();
        $jobId = $data['jobid'];
        $this->repository->updateDistance($jobId, $data);
        $this->repository->updateJobInformation($jobId, $data);
        return response('Record updated!');
    }

    /**
     * Reopen a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->reopen($data);
        return response($response);
    }

    /**
     * Resend push notifications for a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');
        return response(['success' => 'Push sent']);
    }

    /**
     * Resend SMS notifications for a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }
}
