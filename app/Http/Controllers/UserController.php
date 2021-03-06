<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\ObjectiveRequest;
use App\User;
use App\Action;
use App\Objective;
use App\Charts\SampleChart;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\UserRequest;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * 要登入才能用的Controller
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function listOKR(Request $request, User $user)
    {
        $this->authorize('view', $user);

        $okrsWithPage = $user->getOkrsWithPage($request);

        $data = [
            'owner' => $user,
            'okrs' => $okrsWithPage['okrs'],
            'pageInfo' => $okrsWithPage['pageInfo'],
            'st_date' => $request->input('st_date', ''),
            'fin_date' => $request->input('fin_date', ''),
            'order' => $request->input('order', ''),
        ];
        return view('user.okr', $data);
    }

    public function listAction(Request $request, User $user)
    {
        $this->authorize('view', $user);

        $builder = $user->actions();

        if ($request->input('order', '')) {

            #Action是否完成
            if($request->input('isdone', '') == 'true') $builder->whereNotNull('isdone');
            elseif($request->input('isdone', '') == 'false') $builder->where('isdone', null);
            
            #Action狀態
            $now = now()->toDateString();
            switch ($request->input('state', '')) {
                case 'now':
                    $builder->where('started_at', '<=', $now)
                        ->where('finished_at', '>=', $now);
                    break;
                case 'back':
                    $builder->where('finished_at', '<=', $now);
                    break;
                case 'future':
                    $builder->where('started_at', '>=', $now);
                    break;
            }
            
            #Action排序
            if ($order = $request->input('order', '')) { 
                # 判斷value是以 _asc 或者 _desc 结尾來排序
                if (preg_match('/^(.+)_(asc|desc)$/', $order, $m)) {
                    # 判斷是否為指定的接收的參數
                    if (in_array($m[1], ['started_at', 'finished_at', 'priority'])) {   
                        # 開始排序              
                        $builder->orderBy($m[1], $m[2]);
                    }
                }
            }
        } else {
            #預設
            $now = now()->toDateString();
            $builder->where('started_at', '<=', $now)
                ->where('finished_at', '>=', $now)
                ->orderBy('finished_at');
        }
        $pages = $builder->paginate(10)->appends([
            'state' => $request->input('state', ''),
            'isdone' => $request->input('isdone', ''),
            'order' => $request->input('order', '')
        ]);

        $invitations = auth()->user()->invitation->where('model_type', Action::class);
        $data = [
            'owner' => $user,
            'actions' => $pages,
            'pageInfo' => [
                'link' => $pages->render(),
                'totalItem' => $pages->total()
            ],
            'state' => $request->input('state', ''),
            'isdone' => $request->input('isdone', ''),
            'order' => $request->input('order', ''),
            'invitations' => $invitations,
        ];
        return view('user.action', $data);
    }

    public function storeObjective(ObjectiveRequest $request, User $user)
    {
        $this->authorize('storeObjective', $user);

        $objective = $user->addObjective($request);

        return redirect()->to(url()->previous() . '#oid-' . $objective->id);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function settings(Request $request, User $user)
    {
        $this->authorize('view', $user);

        if ($user->id != auth()->user()->id) return redirect()->to(url()->previous());
        $complete = $user->actions()->whereNotNull('isdone');
        $actionComplianceRate = [
            'all' => count($user->actions),
            'complete' => count($complete->get()),
            'delay' => count($complete->whereRaw('finished_at < isdone')->get()),
        ];

        $data = [
            'user' => $user,
            'actionComplianceRate' => $actionComplianceRate,
        ];

        return view('user.settings', $data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  User $user
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        if ($request->name != null) $user->update(['name' => $request->name]);
        if ($request->description != null) $user->update(['description' => $request->description]);
        $user->addAvatar($request);

        return redirect()->route('user.settings', auth()->user()->id);
    }

    public function notifications()
    {
        if (count(auth()->user()->unreadNotifications()->get()) > 4) return auth()->user()->unreadNotifications()->get()->toArray();
        return auth()->user()->notifications()->take(4)->get()->toArray();
    }

    public function readAllNotification()
    {
        auth()->user()->unreadNotifications->markAsRead();
        return redirect()->back();
    }

    public function listNotification()
    {
        $data = [
            'notifications' => auth()->user()->notifications()->get(),
        ];
        return view('user.notifications', $data);
    }

    public function resetPassword(UserRequest $request)
    {
        if (!Auth::Check()) return redirect()->route('user.okr');

        $current_password = Auth::User()->password;
        if (Hash::check($request['current_password'], $current_password)) {
            $user_id = Auth::User()->id;
            $obj_user = User::find($user_id);
            $obj_user->password = Hash::make($request['password']);;
            $obj_user->save();
            return redirect()->back()->with("success", "密碼變更成功！");
        } else {
            $error = array('current_password' => 'Please enter correct current password');
            return redirect()->back()->with("error", "密碼輸入錯誤，請重新輸入！");
        }
    }
}
