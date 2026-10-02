<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentBindReadinessEvent;
use App\Models\DnsAuthoritativeObservationEvent;
use App\Models\DnsServer;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneAgentEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_removes_only_events_older_than_the_cutoff_from_both_tables(): void
    {
        $agent = $this->agent();

        $this->seedEvents($agent, 'dns_authoritative_observation_events', [40, 31, 10, 0], withSequence: true);
        $this->seedEvents($agent, 'dns_agent_bind_readiness_events', [45, 5, 1]);

        Artisan::call('dns-center:prune-agent-events', ['--days' => 30]);

        $this->assertSame(2, DnsAuthoritativeObservationEvent::query()->count());
        $this->assertSame(2, DnsAgentBindReadinessEvent::query()->count());
        $this->assertStringContainsString('observações: 2 evento(s) removidos', Artisan::output());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $agent = $this->agent();
        $this->seedEvents($agent, 'dns_authoritative_observation_events', [40, 2], withSequence: true);

        Artisan::call('dns-center:prune-agent-events', ['--days' => 30, '--dry-run' => true]);

        $this->assertSame(2, DnsAuthoritativeObservationEvent::query()->count());
        $this->assertStringContainsString('1 evento(s) seriam removidos', Artisan::output());
    }

    public function test_rejects_a_retention_shorter_than_seven_days(): void
    {
        $agent = $this->agent();
        $this->seedEvents($agent, 'dns_agent_bind_readiness_events', [2]);

        $this->assertSame(2, Artisan::call('dns-center:prune-agent-events', ['--days' => 3]));
        $this->assertSame(1, DnsAgentBindReadinessEvent::query()->count());
    }

    public function test_deletes_in_chunks_without_leaving_rows_behind(): void
    {
        $agent = $this->agent();
        $rows = [];

        for ($i = 0; $i < 5300; $i++) {
            $rows[] = [
                'dns_agent_id' => $agent->id,
                'event_id' => (string) Str::uuid(),
                'payload_hash' => str_repeat('a', 64),
                'created_at' => now()->subDays(60),
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('dns_agent_bind_readiness_events')->insert($chunk);
        }

        Artisan::call('dns-center:prune-agent-events', ['--days' => 30]);

        $this->assertSame(0, DnsAgentBindReadinessEvent::query()->count());
    }

    public function test_is_scheduled_daily(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('dns-center:prune-agent-events', Artisan::output());
    }

    /**
     * @param  list<int>  $agesInDays
     */
    private function seedEvents(DnsAgent $agent, string $table, array $agesInDays, bool $withSequence = false): void
    {
        foreach ($agesInDays as $index => $age) {
            $row = [
                'dns_agent_id' => $agent->id,
                'event_id' => (string) Str::uuid(),
                'payload_hash' => str_repeat('b', 64),
                'created_at' => now()->subDays($age),
            ];

            if ($withSequence) {
                $row['sequence'] = $index + 1;
                $row['updated_at'] = now()->subDays($age);
            }

            DB::table($table)->insert($row);
        }
    }

    private function agent(): DnsAgent
    {
        $organization = Organization::factory()->create();
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);

        return DnsAgent::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => Str::random(64),
            'token_hash' => hash('sha256', Str::random(96)),
            'reported_hostname' => $server->hostname,
            'registered_ip' => '127.0.0.1',
            'registered_at' => now(),
            'last_seen_at' => now(),
            'metadata' => [],
        ]);
    }
}
