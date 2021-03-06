<?php

namespace App\Console;

use App\Objective;
use App\KeyResult;
use App\Action;
use App\Activity;
use App\KeyResultRecord;

use Notification;
use App\Notifications\CheckNotification;
use App\Notifications\DeadlineNotification;
use App\Notifications\ActionNotification;
use App\Notifications\ActivityNotification;
use App\Notifications\UpdatedNotification;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // 每半小時掃蕩以下排程，即將開始的行事曆活動提醒
        $schedule->call(function () {
            $now = now();
            $future = now()->addMinutes(40);
            $past = now()->subMinutes(40);

            $activities = Activity::where('started_at', '<=', $future)->where('started_at', '>=', $now)->get();
            foreach ($activities as $activity) {
                Notification::send($activity->user, new ActivityNotification($activity));
            }
            $acts = KeyResult::where('updated_at', '>=', $past)->where('updated_at', '<', $now)->get();
            foreach ($acts as $act) {
                Notification::send($act->follower, new ActivityNotification($activity));
            }
            $acts = Action::where('updated_at', '>=', $past)->where('updated_at', '<', $now)->get();
            foreach ($acts as $act) {
                Notification::send($act->follower, new ActivityNotification($activity));
            }
        })->everyThirtyMinutes();

        // 每小時掃蕩以下排程，提醒更新過的KR、Action給追蹤者確認更動內容
        $schedule->call(function () {
            $now = now();
            $past = now()->subHour();
            $krs = KeyResult::where('updated_at', '>=', $past)->get();
            foreach ($krs as $kr) {
                if ($kr->objective->model->follower->count() > 0) {
                    foreach ($kr->objective->model->follower as $follower) {
                        Notification::send($follower->user, new UpdatedNotification($kr));
                    }
                }
            }
            $acts = Action::where('updated_at', '>=', $past)->get();
            foreach ($acts as $act) {
                if ($act->user->follower->count() > 0) {
                    foreach ($act->user->follower as $follower) {
                        Notification::send($follower->user, new UpdatedNotification($act));
                    }
                }
            }
        })->hourly();

        // 每日指定時間進行以下排程
        $schedule->call(function () {
            $now = now()->toDateString();

            $objs = Objective::where('started_at', '<=', $now)->where('finished_at', '>=', $now)->get();
            foreach ($objs as $obj) {
                // 針對O底下的KR做歷史紀錄
                foreach ($obj->keyresults as $keyresult) {
                    $oldAttr['key_results_id'] = $keyresult->id;
                    $oldAttr['history_confidence'] = $keyresult->confidence;
                    $oldAttr['history_value'] = $keyresult->current_value;
                    KeyResultRecord::create($oldAttr);
                }
                // 如果七天內沒更新，要記得去更新Objective
                if ($obj->updated_at < now()->subWeek()) {
                    Notification::send($obj->model->getNotifiableUser(), new CheckNotification($obj));
                }     
                // 如果七天後到期，今天會提醒
                if ($obj->finished_at == now()->subWeek()->toDateString()) {
                    Notification::send($obj->model->getNotifiableUser(), new DeadlineNotification($obj));
                }     
                // 如果今天到期會提醒
                if ($obj->finished_at == now()->toDateString()) {
                    Notification::send($obj->model->getNotifiableUser(), new DeadlineNotification($obj));
                }
            }

            $acts = Action::where('started_at', '<=', $now)->where('finished_at', '>=', $now)->get();
            foreach ($acts as $act) {
                // 如果七天後到期，今天會提醒
                if ($act->finished_at == now()->subWeek()->toDateString()) {
                    Notification::send($act->user, new ActionNotification($act));
                }     
                // 如果今天到期也會提醒
                if ($act->finished_at == now()->toDateString()) {
                    Notification::send($act->user, new ActionNotification($act));
                }
            }
        })->dailyAt('07:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
