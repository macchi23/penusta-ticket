<?php

namespace Database\Seeders;

use App\Models\AllocationTemplate;
use App\Models\AllocationTemplateEntry;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\Stadium;
use Illuminate\Database\Seeder;

class AllocationSeeder extends Seeder
{
    public function run(): void
    {
        $stadium = Stadium::firstOrFail();
        $blocks = Block::whereHas('stand', fn ($q) => $q->where('stadium_id', $stadium->id))->get()->keyBy('code');

        $normalTemplate = AllocationTemplate::create(['stadium_id' => $stadium->id, 'name' => '通常配置']);
        $this->createEntries($normalTemplate, $blocks, awayCodes: ['S1'], bufferCodes: ['S2']);

        $bigAwayTemplate = AllocationTemplate::create(['stadium_id' => $stadium->id, 'name' => '大規模アウェー配置']);
        $this->createEntries($bigAwayTemplate, $blocks, awayCodes: ['S1', 'S2'], bufferCodes: ['S3']);

        $matches = FootballMatch::all();

        foreach ($matches as $match) {
            $isBigMatch = ($match->attributes['opponent_popularity'] ?? 1) >= 5;
            $template = $isBigMatch ? $bigAwayTemplate : $normalTemplate;

            $rows = [];
            $now = now();
            foreach ($template->entries as $entry) {
                $rows[] = [
                    'match_id' => $match->id,
                    'block_id' => $entry->block_id,
                    'allocation' => $entry->allocation,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                MatchBlockAllocation::insert($chunk);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Block>  $blocks
     * @param  array<int, string>  $awayCodes
     * @param  array<int, string>  $bufferCodes
     */
    private function createEntries(AllocationTemplate $template, $blocks, array $awayCodes, array $bufferCodes): void
    {
        $now = now();
        $rows = [];

        foreach ($blocks as $code => $block) {
            $allocation = match (true) {
                in_array($code, $awayCodes, true) => 'away',
                in_array($code, $bufferCodes, true) => 'buffer',
                default => 'home',
            };

            $rows[] = [
                'allocation_template_id' => $template->id,
                'block_id' => $block->id,
                'allocation' => $allocation,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        AllocationTemplateEntry::insert($rows);
    }
}
