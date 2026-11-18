<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\KGarden\AccessClient;
use App\Services\KGarden\UserProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Follows access changes made in ERPNext: a person who lost WMS access loses every WMS right and is
 * signed out everywhere; a changed role or warehouse list is applied and the person signs in again.
 * Scheduled every minute.
 */
class KGardenSyncAccess extends Command
{
    protected $signature = 'kgarden:sync-access';

    protected $description = 'Apply WMS access changes made in ERPNext (K Garden central access)';

    public function handle(AccessClient $access, UserProvisioner $provisioner): int
    {
        if (! config('kgarden.enabled')) {
            return self::SUCCESS;
        }
        // ERPNext's local time; the first run looks back a day. Re-reading a second is harmless.
        $since = Cache::get('kgarden.access_since', now('Asia/Bangkok')->subDay()->format('Y-m-d H:i:s'));
        $latest = $since;
        foreach ($access->accessChanges($since) as $change) {
            $latest = max($latest, substr((string) ($change['changed_at'] ?? $since), 0, 19));
            $user = User::query()->where('email', strtolower((string) $change['user']))->where('kgarden_managed', true)->first();
            if (! $user) {
                continue; // never signed in to the WMS: nothing to change here
            }
            $before = [$user->getRoleNames()->sort()->values()->all(), $user->outlets()->pluck('outlets.id')->sort()->values()->all()];
            if ($change['granted'] ?? false) {
                $provisioner->sync($change);
            } else {
                $provisioner->strip($user);
            }
            $user->refresh();
            $after = [$user->getRoleNames()->sort()->values()->all(), $user->outlets()->pluck('outlets.id')->sort()->values()->all()];
            if ($before !== $after || ! ($change['granted'] ?? false)) {
                $ended = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                $this->info("{$user->email}: access ".(($change['granted'] ?? false) ? 'changed' : 'removed').", {$ended} session(s) ended");
            }
        }
        Cache::forever('kgarden.access_since', $latest);

        return self::SUCCESS;
    }
}
