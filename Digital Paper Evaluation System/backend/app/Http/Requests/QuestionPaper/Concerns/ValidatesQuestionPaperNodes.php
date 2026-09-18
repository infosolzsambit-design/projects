<?php

namespace App\Http\Requests\QuestionPaper\Concerns;

use Illuminate\Validation\Validator;

/**
 * Shared recursive walk over a submitted question paper structure tree —
 * used by both StoreQuestionPaperRequest (paper + structure created
 * together in one request) and UpdateQuestionPaperRequest (structure
 * replaced on an existing paper), so both report errors at identical
 * dotted paths ("groups.0.children.1.children.0.marks") regardless of
 * which endpoint the person's request went through.
 */
trait ValidatesQuestionPaperNodes
{
    protected function validateGroupsStructure(Validator $validator): void
    {
        foreach ((array) $this->input('groups', []) as $index => $group) {
            $this->validateNode($validator, (array) $group, "groups.{$index}");
        }
    }

    /**
     * Validates one node of the structure tree and recurses into its
     * children (if any), building up the dotted error path as it goes.
     *
     * @param  array<string, mixed>  $node
     */
    private function validateNode(Validator $validator, array $node, string $path): void
    {
        $label = trim((string) ($node['label'] ?? ''));
        if ($label === '') {
            $validator->errors()->add("{$path}.label", 'Name is required.');
        } elseif (strlen($label) > 255) {
            $validator->errors()->add("{$path}.label", 'Name must not exceed 255 characters.');
        }

        $instruction = $node['instruction'] ?? null;
        if ($instruction !== null && strlen((string) $instruction) > 2000) {
            $validator->errors()->add("{$path}.instruction", 'Instruction must not exceed 2000 characters.');
        }

        $mode = $node['mode'] ?? null;
        if (! in_array($mode, ['leaf', 'all', 'choose'], true)) {
            $validator->errors()->add("{$path}.mode", 'Invalid selection mode.');

            return;
        }

        if ($mode === 'leaf') {
            $marks = $node['marks'] ?? null;
            if (! is_numeric($marks) || (int) $marks < 1) {
                $validator->errors()->add("{$path}.marks", 'Marks are required.');
            }

            // Bloom's Taxonomy level (K1, K2, ...) and Course Outcome (CO1,
            // CO2, ...) — optional on every paper (plenty print neither),
            // so nothing is required here; just bounded in length to match
            // the column width.
            foreach (['bloom_level', 'co'] as $field) {
                $value = $node[$field] ?? null;
                if ($value !== null && $value !== '' && strlen((string) $value) > 10) {
                    $validator->errors()->add("{$path}.{$field}", 'Must not exceed 10 characters.');
                }
            }

            return;
        }

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $childCount = count($children);
        $minChildren = $mode === 'choose' ? 2 : 1;

        if ($childCount < $minChildren) {
            $validator->errors()->add(
                "{$path}.children",
                $mode === 'choose'
                    ? 'A "choose" item needs at least 2 options below it.'
                    : 'Add at least one question below.',
            );
        }

        if ($mode === 'choose') {
            $slotsOverride = $node['slots_override'] ?? null;
            if ($slotsOverride !== null && $slotsOverride !== '' && (! is_numeric($slotsOverride) || (int) $slotsOverride < 1)) {
                $validator->errors()->add("{$path}.slots_override", 'Must be a whole number of 1 or more.');
            }

            // Bounds are checked against the *slot* count, not the raw
            // direct-children count — a child can itself be an "all"
            // sub-group standing in for several real questions (e.g. three
            // labeled sub-groups combined under one "choose 11 of 15"
            // pool), and a plain "choose_count" comparison against
            // count($children) (3, in that example) would wrongly reject
            // it. See self::slotCount().
            //
            // A reviewer's own slots_override, when set, wins outright —
            // see that column's own migration docblock for why an
            // auto-computed total can be wrong in the first place.
            $slotCount = ! empty($node['slots_override'])
                ? (int) $node['slots_override']
                : array_sum(array_map(fn ($child) => $this->slotCount((array) $child), $children));
            $chooseCount = $node['choose_count'] ?? null;
            if (! is_numeric($chooseCount) || (int) $chooseCount < 1) {
                $validator->errors()->add("{$path}.choose_count", 'Enter how many of these must be attempted.');
            } elseif ((int) $chooseCount > $slotCount) {
                $validator->errors()->add("{$path}.choose_count", "Cannot require more than the {$slotCount} option(s) below.");
            }
        }

        foreach ($children as $childIndex => $child) {
            $this->validateNode($validator, (array) $child, "{$path}.children.{$childIndex}");
        }
    }

    /**
     * The number of actual selectable question positions in a (sub)tree —
     * a "leaf" or "choose" node is always exactly one position from its
     * parent's point of view (a "choose" node's alternatives are still
     * only ever one question being answered), while an "all" node is pure
     * grouping and contributes the sum of its own children's slot counts.
     * This is what a "choose" node's own bounds are validated against
     * (see above), not the node's raw direct-children count, so a group
     * of sub-groups pools correctly.
     *
     * @param  array<string, mixed>  $node
     */
    private function slotCount(array $node): int
    {
        if (($node['mode'] ?? null) !== 'all') {
            return 1;
        }

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];

        return array_sum(array_map(fn ($child) => $this->slotCount((array) $child), $children));
    }
}
