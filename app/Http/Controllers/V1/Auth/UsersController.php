<?php

namespace App\Http\Controllers\V1\Auth;

use App\Role;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Mail\UserCredentials;
use Illuminate\Http\Response;
use App\Http\Resources\UserResource;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Http\Resources\UserCollection;
use Illuminate\Support\Facades\Validator;

class UsersController extends Controller
{
    /**
     * Constructor for the controller
     *
     * __construct
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('access.role:administrator');
    }

    /**
     * Retrieve a list of paginated users
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|UserCollection
     */
    public function index(Request $request)
    {
        try {
            $transforms = [
                'name' => 'name',
                'email' => 'email',
                'role' => 'role',
                'is_active' => 'is_active',
                'date_added' => 'created_at',
                'verified_at' => 'email_verified_at'
            ];

            // Get the limit of records per page
            $limit = $request->has('limit') ? $request->get('limit') : 10;

            if ($request->has('sort')) {
                $sort = json_decode(base64_decode($request->get('sort')), true);

                if (array_has($transforms, $sort['column']) == false) {
                    return response()->json(['status' => 'error', 'message' => 'The sort parameters are out of scope', 'data' => []], Response::HTTP_BAD_REQUEST);
                }

                $column = $transforms[$sort['column']];
                $direction = $sort['direction'];
            } else {
                $column = 'created_at';
                $direction = 'desc';
            }

            if ($request->has('search')) {
                $search = json_decode(base64_decode($request->get('search')), true);

                if (array_has($transforms, $search['column']) == false) {
                    return response()->json(['status' => 'error', 'message' => 'The search parameters are out of scope', 'data' => []], Response::HTTP_BAD_REQUEST);
                }

                // Get a list of user records and parse them as an array
                $users = User::query()->withTrashed()->where($transforms[$search['column']], 'LIKE', '%'.$search['value'].'%')->orderBy($column, $direction)->paginate($limit);
            } else {
                // Get a list of user records and parse them as an array
                $users = User::query()->withTrashed()->orderBy($column, $direction)->paginate($limit);
            }

            $response = new UserCollection($users);

            return $response;
        } catch(\Exception $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage(), 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Retrieve a list of available user roles
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function roles()
    {
        try {
            $roles = Role::query()->select('id', 'name', 'slug', 'created_at AS date_added')->get()->toArray();

            return response()->json(['status' => 'success', 'data' => $roles], Response::HTTP_OK);
        } catch(\Exception $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage(), 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|UserResource
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|max:255',
            'last_name' => 'required|max:255',
            'email' => 'required|email|max:255|unique:users',
            'role_select' => 'required|not_in:0'
        ]);

        if ($validator->fails()) {
            $errorResponse = [];
            $errors = array_map(function ($value) {
                return implode(' ', $value);
            }, $validator->errors()->toArray());
            $errorKeys = $validator->errors()->keys();

            foreach ($errorKeys as $key)
            {
                $array = ['field' => $key, 'error' => $errors[$key]];
                array_push($errorResponse, $array);
            }

            return response()->json(['status' => 'error', 'message' => $errorResponse, 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            // Get the role selected
            $roleId = $request->get('role_select');

            // Create a random string of 10 Characters for the password
            $password = str_random(10);

            // Create the new user record
            $user = new User([
                'name' => $request->get('first_name').' '.$request->get('last_name'),
                'email' => $request->get('email'),
                'password' => Hash::make($password),
                'is_active' => 1,
                'email_verified_at' => Carbon::now()->toDateTimeString()
            ]);
            $user->save();

            // Attach role selected to the user
            $user->roles()->attach($roleId);

            // Send an email with the password generated
            $email = new UserCredentials(new User(['name' => $request->get('name'), 'password' => $password, 'email' => $request->get('email')]));
            Mail::to($request->get('email'))->sendNow($email);

            $resource = new UserResource($user);
            $response = $resource->additional(['status' => 'success', 'message' => 'Successfully created user']);

            return $response;
        } catch(\Exception $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage(), 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show the given record.
     *
     * @param $id
     * @return \Illuminate\Http\JsonResponse|UserResource
     */
    public function show($id)
    {
        try {
            $user = User::query()->withTrashed()->where('id', '=', $id)->first();

            if (count($user) !== 0)
            {
                $resource = new UserResource($user);
                $response = $resource->additional(['status' => 'success']);

                return $response;
            } else {
                return response()->json(['status' => 'error', 'message' => 'User with id '.$id.' not found', 'data' => []], Response::HTTP_NOT_FOUND);
            }
        } catch(\Exception $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage(), 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the given record.
     *
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse|UserResource
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|max:255',
            'last_name' => 'required|max:255',
            'email' => 'required|email|max:255'
        ]);

        if ($validator->fails()) {
            $errorResponse = [];
            $errors = array_map(function ($value) {
                return implode(' ', $value);
            }, $validator->errors()->toArray());
            $errorKeys = $validator->errors()->keys();

            foreach ($errorKeys as $key)
            {
                $array = ['field' => $key, 'error' => $errors[$key]];
                array_push($errorResponse, $array);
            }

            return response()->json(['status' => 'error', 'message' => $errorResponse, 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $user = User::query()->findOrFail($id);

            // Update the user's details
            $user->update([
                'name' => ucwords($request->get('first_name').' '.$request->get('last_name')),
                'email' => $request->get('email')
            ]);

            $resource = new UserResource($user);
            $response = $resource->additional(['status' => 'success', 'message' => 'Successfully updated '.$user['name']]);

            return $response;
        } catch(\Exception $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage(), 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the role of the given record.
     *
     * @param Request $request
     * @param $id
     * @return UserResource|\Illuminate\Http\JsonResponse
     */
    public function role(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'role_select' => 'required|not_in:0'
        ]);

        if ($validator->fails()) {
            $errorResponse = [];
            $errors = array_map(function ($value) {
                return implode(' ', $value);
            }, $validator->errors()->toArray());
            $errorKeys = $validator->errors()->keys();

            foreach ($errorKeys as $key)
            {
                $array = ['field' => $key, 'error' => $errors[$key]];
                array_push($errorResponse, $array);
            }

            return response()->json(['status' => 'error', 'message' => $errorResponse, 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $roleId = $request->get('role_select');

            $user = User::query()->findOrFail($id);

            // Detach the role form the user and attach a new role
            $user->roles()->detach();
            $user->roles()->attach($roleId);

            $role = Role::query()->findOrFail($roleId);

            $resource = new UserResource($user);
            $response = $resource->additional(['status' => 'success', 'message' => 'Successfully updated the role for '.$user['name'].' to '.$role['name']]);

            return $response;
        } catch(\Exception $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage(), 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Deactivate the given record.
     *
     * @param $id
     * @return UserResource|\Illuminate\Http\JsonResponse
     */
    public function deactivate($id)
    {
        try {
            $user = User::query()->findOrFail($id);

            // Perform a soft delete(deactivate)
            $user->update(['is_active' => 0]);
            $user->delete();

            $resource = new UserResource($user);
            $response = $resource->additional(['status' => 'success', 'message' => 'Successfully deactivated '.$user['name']]);

            return $response;
        } catch(\Exception $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage(), 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reactivate the given record.
     *
     * @param $id
     * @return UserResource|\Illuminate\Http\JsonResponse
     */
    public function reactivate($id)
    {
        try {
            $user = User::query()->withTrashed()->where('id', '=', $id);
            $user->update(['is_active' => 1]);

            // Restore(reactivate) the user model
            $user->restore();

            $resource = new UserResource($user->first());
            $response = $resource->additional(['status' => 'success', 'message' => 'Successfully reactivated '.$user->first()['name']]);

            return $response;
        } catch(\Exception $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage(), 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete the given record.
     *
     * @param $id
     * @return UserResource|\Illuminate\Http\JsonResponse
     */
    public function delete($id)
    {
        try {
            $user = User::query()->withTrashed()->where('id', '=', $id);

            if (count($user->first()) !== 0)
            {
                $data = $user->first();

                // Perform a permanent delete
                $user->forceDelete();

                $resource = new UserResource($data);
                $response = $resource->additional(['status' => 'success', 'message' => 'Successfully deleted '.$data['name']]);

                return $response;
            } else {
                return response()->json(['status' => 'error', 'message' => 'User with id '.$id.' not found', 'data' => []], Response::HTTP_NOT_FOUND);
            }
        } catch(\Exception $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage(), 'data' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
