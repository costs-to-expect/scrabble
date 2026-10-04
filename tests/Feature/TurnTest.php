<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ShareToken;
use App\Notifications\ApiError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\FakesTheApi;
use Tests\TestCase;

/**
 * A turn is a word (or just a score), a pass or an adjustment. The browser sends a turn with an id it made up, the app
 * adds it to the player's stored score sheet, works out the totals and logs it. A signed-in player scores on the game
 * screen, everyone else scores through the public share link the owner of the game sent them, the two are separate
 * controllers with the same rules so every test runs against both.
 */
class TurnTest extends TestCase
{
    use FakesTheApi;
    use RefreshDatabase;

    private const SHEET = 'api.test/v3/resource-types/rt-1/resources/r-1/items/g-1/data/p-1';

    private const AT = '2026-10-04T19:30:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AT));
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function modes(): array
    {
        return [
            'signed in' => [false],
            'public share link' => [true],
        ];
    }

    private function fakeScoring(array $sheet, array $overrides = []): void
    {
        $this->fakeApi($overrides + [
            self::SHEET => $this->byMethod([
                'GET' => Http::response(['key' => 'p-1', 'value' => $sheet], 200),
                'PATCH' => Http::response(null, 204),
            ]),
            $this->items('/g-1/log') => Http::response(['id' => 'log-1'], 201),
        ]);
    }

    private function shareLink(): void
    {
        if (ShareToken::query()->where('token', 'public-token')->exists()) {
            return;
        }

        $share = new ShareToken();
        $share->token = 'public-token';
        $share->game_id = 'g-1';
        $share->player_id = 'p-1';
        $share->parameters = [
            'resource_type_id' => 'rt-1',
            'resource_id' => 'r-1',
            'game_id' => 'g-1',
            'player_id' => 'p-1',
            'player_name' => 'Ada',
            'owner_bearer' => 'owner-bearer',
        ];
        $share->save();
    }

    /**
     * @param 'add'|'change'|'remove'|'restore' $operation
     */
    private function send(string $operation, array $payload, bool $public): TestResponse
    {
        $path = ['add' => 'turn', 'change' => 'turn', 'remove' => 'turn/remove', 'restore' => 'turn/restore'][$operation];
        if ($operation === 'change') {
            $payload += ['replace' => true];
        }

        if ($public) {
            $this->shareLink();

            return $this->postJson("/public/score-sheet/public-token/{$path}", $payload);
        }

        // The browser sends its cookies with the script's JSON request, a test request has to ask to.
        return $this->signedIn()->withCredentials()->postJson("/game/{$path}", $payload + ['game_id' => 'g-1', 'player_id' => 'p-1']);
    }

    /**
     * @return array<string, mixed> the score sheet saved by the last PATCH
     */
    private function savedSheet(): array
    {
        $saved = $this->sentTo('PATCH', self::SHEET);
        self::assertCount(1, $saved, 'the score sheet should be saved exactly once');

        return json_decode($saved[0]['value'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertNothingSaved(): void
    {
        self::assertCount(0, $this->sentTo('PATCH', self::SHEET), 'nothing should have been saved');
        self::assertCount(0, $this->sentTo('POST', $this->items('/g-1/log')), 'nothing should have been logged');
    }

    /**
     * A turn as the browser sends it, a word unless it says otherwise
     *
     * @return array<string, mixed>
     */
    private function turnOf(string $id, array $turn = []): array
    {
        return $turn + ['id' => $id, 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'bingo' => false];
    }

    /**
     * A turn as it is stored
     *
     * @return array<string, mixed>
     */
    private function stored(string $id, string $word, int $score, bool $bingo = false, bool $removed = false): array
    {
        return $this->word($word, $score, $bingo, $id, $removed);
    }

    // Adding

    #[DataProvider('modes')]
    public function test_adding_a_word_stores_it_and_works_out_the_totals(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'FAX', 33)]));

        $this->send('add', $this->turnOf('turn-0002'), $public)
            ->assertOk()
            ->assertExactJson([
                'message' => 'Turn saved',
                // The sheet as it is now, the browser carries on from it
                'sheet' => [
                    'turns' => [
                        $this->stored('turn-0001', 'FAX', 33),
                        ['id' => 'turn-0002', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'bingo' => false, 'note' => '', 'at' => self::AT, 'removed' => false],
                    ],
                    'score' => ['total' => 85, 'turns' => 2, 'words' => 2, 'bingos' => 0, 'best' => 52, 'lowest' => 33],
                ],
            ]);

        $saved = $this->savedSheet();
        self::assertSame(['turn-0001', 'turn-0002'], array_column($saved['turns'], 'id'));
        self::assertSame(['total' => 85, 'turns' => 2, 'words' => 2, 'bingos' => 0, 'best' => 52, 'lowest' => 33], $saved['score']);
    }

    #[DataProvider('modes')]
    public function test_a_bingo_is_fifty_points_on_top_of_the_word(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'FAX', 33)]));

        $this->send('add', $this->turnOf('turn-0002', ['word' => 'RETAINS', 'score' => 68, 'bingo' => true]), $public)
            ->assertOk()
            ->assertJsonPath('sheet.score', ['total' => 151, 'turns' => 2, 'words' => 2, 'bingos' => 1, 'best' => 118, 'lowest' => 33])
            // The word's own score is kept, the bonus is worked out
            ->assertJsonPath('sheet.turns.1.score', 68)
            ->assertJsonPath('sheet.turns.1.bingo', true);

        self::assertSame(151, $this->savedSheet()['score']['total']);
    }

    #[DataProvider('modes')]
    public function test_a_pass_scores_nothing_and_is_a_turn(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'FAX', 33)]));

        $this->send('add', ['id' => 'turn-0002', 'kind' => 'pass'], $public)
            ->assertOk()
            ->assertJsonPath('sheet.score.total', 33)
            ->assertJsonPath('sheet.score.turns', 2)
            ->assertJsonPath('sheet.score.words', 1)
            ->assertJsonPath('sheet.turns.1', ['id' => 'turn-0002', 'kind' => 'pass', 'word' => '', 'score' => 0, 'bingo' => false, 'note' => '', 'at' => self::AT, 'removed' => false]);
    }

    #[DataProvider('modes')]
    public function test_an_adjustment_moves_the_score_up_or_down_and_is_not_a_turn(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'FAX', 33)]));

        $this->send('add', ['id' => 'turn-0002', 'kind' => 'adjust', 'score' => -7, 'note' => ' Tiles left '], $public)
            ->assertOk()
            ->assertJsonPath('sheet.score.total', 26)
            ->assertJsonPath('sheet.score.turns', 1)
            ->assertJsonPath('sheet.turns.1.note', 'Tiles left')
            ->assertJsonPath('sheet.turns.1.score', -7);

        $this->send('add', ['id' => 'turn-0003', 'kind' => 'adjust', 'score' => '12', 'note' => 'Went out'], $public)->assertOk();
    }

    #[DataProvider('modes')]
    public function test_the_word_is_optional(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet());

        $this->send('add', ['id' => 'turn-0001', 'kind' => 'word', 'score' => 25], $public)
            ->assertOk()
            ->assertJsonPath('sheet.turns.0.word', '')
            ->assertJsonPath('sheet.score.total', 25);

        // An empty box is sent as an empty string, which Laravel turns into null on the way in
        $this->send('add', ['id' => 'turn-0002', 'kind' => 'word', 'word' => '', 'score' => 25], $public)->assertOk();
    }

    #[DataProvider('modes')]
    public function test_a_word_is_trimmed_and_put_in_capitals(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet());

        $this->send('add', $this->turnOf('turn-0001', ['word' => '  quiz ']), $public)
            ->assertOk()
            ->assertJsonPath('sheet.turns.0.word', 'QUIZ');

        self::assertSame('QUIZ', $this->savedSheet()['turns'][0]['word']);
    }

    #[DataProvider('modes')]
    public function test_words_in_other_alphabets_are_words_too(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet());

        $this->send('add', $this->turnOf('turn-0001', ['word' => 'żółć', 'score' => 21]), $public)
            ->assertOk()
            ->assertJsonPath('sheet.turns.0.word', 'ŻÓŁĆ');
    }

    #[DataProvider('modes')]
    public function test_a_score_can_be_sent_as_a_string_of_digits_and_a_bingo_as_a_form_would_send_it(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet());

        $this->send('add', $this->turnOf('turn-0001', ['score' => '52', 'bingo' => '1']), $public)
            ->assertOk()
            ->assertJsonPath('sheet.turns.0.score', 52)
            ->assertJsonPath('sheet.turns.0.bingo', true);
    }

    #[DataProvider('modes')]
    public function test_only_the_fields_of_a_turn_are_taken_from_the_browser(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet());

        $this->send('add', $this->turnOf('turn-0001', ['removed' => true, 'at' => '1999-01-01T00:00:00Z', 'total' => 9999, 'turns' => [], 'score_sheet' => 'x']), $public)
            ->assertOk()
            ->assertJsonPath('sheet.turns.0.removed', false)
            ->assertJsonPath('sheet.turns.0.at', self::AT)
            ->assertJsonPath('sheet.score.total', 52);

        self::assertSame(self::AT, $this->savedSheet()['turns'][0]['at']);
    }

    #[DataProvider('modes')]
    public function test_the_other_things_stored_on_the_sheet_are_kept(bool $public): void
    {
        $sheet = $this->scoreSheet([$this->stored('turn-0001', 'FAX', 33)]) + ['note-from-another-client' => 'keep me'];
        $this->fakeScoring($sheet);

        $this->send('add', $this->turnOf('turn-0002'), $public)->assertOk();

        self::assertSame('keep me', $this->savedSheet()['note-from-another-client']);
    }

    // The same turn twice: the answer to a save got lost and the browser sends it again

    #[DataProvider('modes')]
    public function test_a_turn_that_is_sent_again_is_not_scored_twice(bool $public): void
    {
        $sheet = $this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52)]);
        $this->fakeScoring($sheet);

        $this->send('add', $this->turnOf('turn-0001'), $public)
            ->assertOk()
            ->assertJsonPath('message', 'Turn saved')
            ->assertJsonPath('sheet.score.total', 52);

        $this->assertNothingSaved();
    }

    #[DataProvider('modes')]
    public function test_a_turn_id_that_has_been_used_for_something_else_is_a_conflict(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'FAX', 33)]));

        $this->send('add', $this->turnOf('turn-0001'), $public)
            ->assertStatus(409)
            ->assertJsonPath('message', 'That turn has already been scored')
            // The browser catches up with what is really on the sheet
            ->assertJsonPath('sheet.turns.0.word', 'FAX')
            ->assertJsonPath('sheet.score.total', 33);

        $this->assertNothingSaved();
    }

    #[DataProvider('modes')]
    public function test_a_score_sheet_has_room_for_a_hundred_and_fifty_turns(bool $public): void
    {
        $turns = [];
        for ($i = 1; $i <= 150; $i++) {
            $turns[] = $this->stored(sprintf('turn-%04d', $i), 'AX', 9);
        }
        $this->fakeScoring($this->scoreSheet($turns));

        $this->send('add', $this->turnOf('turn-9999'), $public)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This score sheet is full');

        $this->assertNothingSaved();
    }

    // Changing

    #[DataProvider('modes')]
    public function test_changing_a_turn_rewrites_it_in_place_and_keeps_when_it_was_played(bool $public): void
    {
        $first = ['at' => '2026-10-04T18:00:00Z'] + $this->stored('turn-0001', 'QUIZ', 52);
        $this->fakeScoring($this->scoreSheet([$first, $this->stored('turn-0002', 'FAX', 33)]));

        $this->send('change', $this->turnOf('turn-0001', ['word' => 'QUIZZES', 'score' => 77]), $public)
            ->assertOk()
            ->assertJsonPath('sheet.score.total', 110)
            ->assertJsonPath('sheet.score.best', 77);

        $saved = $this->savedSheet();
        self::assertSame(['turn-0001', 'turn-0002'], array_column($saved['turns'], 'id'));
        self::assertSame('QUIZZES', $saved['turns'][0]['word']);
        self::assertSame('2026-10-04T18:00:00Z', $saved['turns'][0]['at']);
    }

    #[DataProvider('modes')]
    public function test_a_turn_can_change_from_one_kind_to_another_and_nothing_of_the_old_one_is_left(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'RETAINS', 68, true)]));

        $this->send('change', ['id' => 'turn-0001', 'kind' => 'pass'], $public)
            ->assertOk()
            ->assertJsonPath('sheet.turns.0', ['id' => 'turn-0001', 'kind' => 'pass', 'word' => '', 'score' => 0, 'bingo' => false, 'note' => '', 'at' => self::AT, 'removed' => false])
            ->assertJsonPath('sheet.score', ['total' => 0, 'turns' => 1, 'words' => 0, 'bingos' => 0, 'best' => null, 'lowest' => null]);
    }

    #[DataProvider('modes')]
    public function test_changing_a_turn_to_what_it_already_is_changes_nothing(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52)]));

        $this->send('change', $this->turnOf('turn-0001'), $public)->assertOk()->assertJsonPath('message', 'Turn saved');

        $this->assertNothingSaved();
    }

    #[DataProvider('modes')]
    public function test_a_turn_that_is_not_on_the_sheet_cannot_be_changed(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52)]));

        $this->send('change', $this->turnOf('turn-0404'), $public)
            ->assertStatus(422)
            ->assertJsonPath('message', 'That turn is not on the score sheet');

        $this->assertNothingSaved();
    }

    #[DataProvider('modes')]
    public function test_a_turn_that_has_been_removed_cannot_be_changed(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52, false, true)]));

        $this->send('change', $this->turnOf('turn-0001', ['score' => 60]), $public)
            ->assertStatus(422)
            ->assertJsonPath('message', 'That turn has been removed');

        $this->assertNothingSaved();
    }

    // Removing and putting back: nothing is ever taken out of the stored sheet

    #[DataProvider('modes')]
    public function test_removing_a_turn_marks_it_removed_and_it_no_longer_counts(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52), $this->stored('turn-0002', 'FAX', 33)]));

        $this->send('remove', ['id' => 'turn-0001'], $public)
            ->assertOk()
            ->assertJsonPath('sheet.score', ['total' => 33, 'turns' => 1, 'words' => 1, 'bingos' => 0, 'best' => 33, 'lowest' => 33])
            // It stays on the sheet with every key, the browser can put it back
            ->assertJsonPath('sheet.turns.0', $this->stored('turn-0001', 'QUIZ', 52, false, true))
            ->assertJsonCount(2, 'sheet.turns');

        $saved = $this->savedSheet();
        self::assertSame([true, false], array_column($saved['turns'], 'removed'));
        self::assertSame($this->stored('turn-0001', 'QUIZ', 52, false, true), $saved['turns'][0]);
    }

    #[DataProvider('modes')]
    public function test_a_removed_turn_can_be_put_back(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52, false, true), $this->stored('turn-0002', 'FAX', 33)]));

        $this->send('restore', ['id' => 'turn-0001'], $public)
            ->assertOk()
            ->assertJsonPath('sheet.score.total', 85)
            ->assertJsonPath('sheet.turns.0.removed', false);

        self::assertSame([false, false], array_column($this->savedSheet()['turns'], 'removed'));
    }

    #[DataProvider('modes')]
    public function test_removing_a_turn_that_is_already_off_and_restoring_one_that_is_on_are_retries(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52, false, true), $this->stored('turn-0002', 'FAX', 33)]));

        $this->send('remove', ['id' => 'turn-0001'], $public)->assertOk()->assertJsonPath('message', 'Turn saved');
        $this->send('restore', ['id' => 'turn-0002'], $public)->assertOk()->assertJsonPath('message', 'Turn saved');

        $this->assertNothingSaved();
    }

    #[DataProvider('modes')]
    public function test_a_turn_that_is_not_on_the_sheet_cannot_be_removed_or_put_back(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52)]));

        foreach (['remove', 'restore'] as $operation) {
            $this->send($operation, ['id' => 'turn-0404'], $public)
                ->assertStatus(422)
                ->assertJsonPath('message', 'That turn is not on the score sheet');
        }

        $this->assertNothingSaved();
    }

    #[DataProvider('modes')]
    public function test_removing_needs_the_id_of_a_turn(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52)]));

        foreach ([[], ['id' => ''], ['id' => 'x'], ['id' => ['turn-0001']]] as $payload) {
            $this->send('remove', $payload, $public)->assertStatus(422)->assertJsonPath('message', 'The turn needs an id');
        }

        $this->assertNothingSaved();
    }

    // Corrections can be switched off, then a turn is final once it is added

    #[DataProvider('modes')]
    public function test_with_corrections_off_a_turn_can_still_be_added(bool $public): void
    {
        config(['app.config.score_corrections' => false]);
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52)]));

        $this->send('add', $this->turnOf('turn-0002', ['word' => 'FAX', 'score' => 33]), $public)->assertOk();
        self::assertSame(2, count($this->savedSheet()['turns']));
    }

    #[DataProvider('modes')]
    public function test_with_corrections_off_a_turn_cannot_be_changed_removed_or_put_back(bool $public): void
    {
        config(['app.config.score_corrections' => false]);
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52), $this->stored('turn-0002', 'FAX', 33, false, true)]));

        foreach ([
            ['change', $this->turnOf('turn-0001', ['score' => 60])],
            ['remove', ['id' => 'turn-0001']],
            ['restore', ['id' => 'turn-0002']],
        ] as [$operation, $payload]) {
            $this->send($operation, $payload, $public)
                ->assertForbidden()
                ->assertJsonPath('message', 'Turns cannot be changed')
                // Told what is on the sheet, so the screen can catch up
                ->assertJsonPath('sheet.score.total', 52);
        }

        $this->assertNothingSaved();
    }

    // What is not allowed

    /**
     * @return array<string, array{array<string, mixed>, string}> the turn and what is said about it
     */
    public static function turnsThatAreNotAllowed(): array
    {
        $word = ['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'bingo' => false];

        return [
            'no id' => [['id' => null] + $word, 'The turn needs an id'],
            'an id that is too short' => [['id' => 'abc'] + $word, 'The turn needs an id'],
            'an id with a space in it' => [['id' => 'turn 0001'] + $word, 'The turn needs an id'],
            'an id that is too long' => [['id' => str_repeat('a', 41)] + $word, 'The turn needs an id'],
            'an id that is not a string' => [['id' => 12345678] + $word, 'The turn needs an id'],
            'no kind' => [['kind' => null] + $word, 'That is not a kind of turn'],
            'a kind that does not exist' => [['kind' => 'dance'] + $word, 'That is not a kind of turn'],
            'a bingo that is neither yes nor no' => [['bingo' => 'maybe'] + $word, 'Bingo has to be yes or no'],
            'a word score of nothing' => [['score' => 0] + $word, 'A word scores between 1 and 999'],
            'a negative word score' => [['score' => -5] + $word, 'A word scores between 1 and 999'],
            'a word score that is too high' => [['score' => 1000] + $word, 'A word scores between 1 and 999'],
            'a word score that is far too high' => [['score' => '10000'] + $word, 'The score has to be a whole number'],
            'a word score that is 999 and a bit' => [['score' => 999.5] + $word, 'The score has to be a whole number'],
            'a word score that is not a number' => [['score' => 'lots'] + $word, 'The score has to be a whole number'],
            'no word score' => [['score' => null] + $word, 'The score has to be a whole number'],
            'a word score as a decimal' => [['score' => 52.5] + $word, 'The score has to be a whole number'],
            'a word with a number in it' => [['word' => 'QU1Z'] + $word, 'A word is letters only, no more than 15 of them'],
            'a word with a space in it' => [['word' => 'QUIZ ZY'] + $word, 'A word is letters only, no more than 15 of them'],
            'a word with a hyphen' => [['word' => 'WELL-KNOWN'] + $word, 'A word is letters only, no more than 15 of them'],
            'a word longer than the board' => [['word' => str_repeat('A', 16)] + $word, 'A word is letters only, no more than 15 of them'],
            'a word that is not a string' => [['word' => ['QUIZ']] + $word, 'A word is letters only, no more than 15 of them'],
            'a pass that scores' => [['kind' => 'pass', 'score' => 5, 'bingo' => false, 'word' => ''] + $word, 'A pass scores nothing'],
            'a pass that is a bingo' => [['kind' => 'pass', 'score' => 0, 'bingo' => true, 'word' => ''] + $word, 'A pass scores nothing'],
            'an adjustment of nothing' => [['kind' => 'adjust', 'score' => 0, 'note' => ''] + $word, 'An adjustment is more than 0 and no more than 999, up or down'],
            'an adjustment that is too big' => [['kind' => 'adjust', 'score' => -1000, 'note' => ''] + $word, 'An adjustment is more than 0 and no more than 999, up or down'],
            'an adjustment of 1000 as text' => [['kind' => 'adjust', 'score' => '1000', 'note' => ''] + $word, 'An adjustment is more than 0 and no more than 999, up or down'],
            'an adjustment that is a bingo' => [['kind' => 'adjust', 'score' => 5, 'bingo' => true, 'note' => ''] + $word, 'An adjustment is not a bingo'],
            'a note that is too long' => [['kind' => 'adjust', 'score' => -5, 'note' => str_repeat('x', 31)] + $word, 'The note can be no more than 30 characters'],
            'a note that is not a string' => [['kind' => 'adjust', 'score' => -5, 'note' => ['Tiles']] + $word, 'The note can be no more than 30 characters'],
        ];
    }

    #[DataProvider('turnsThatAreNotAllowed')]
    public function test_a_turn_the_rules_do_not_allow_is_refused_and_nothing_is_saved(array $turn, string $message): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0000', 'FAX', 33)]));

        // The rules are the same for the signed-in screen and the share link, so each turn goes to both
        foreach ([false, true] as $public) {
            $this->send('add', $turn, $public)
                ->assertStatus(422)
                ->assertJsonPath('message', $message);
        }

        $this->assertNothingSaved();
    }

    #[DataProvider('turnsThatAreNotAllowed')]
    public function test_a_change_is_held_to_the_same_rules(array $turn, string $message): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'FAX', 33)]));

        $this->send('change', $turn, false)
            ->assertStatus(422)
            ->assertJsonPath('message', $message);

        $this->assertNothingSaved();
    }

    public function test_the_edges_of_what_is_allowed_are_allowed(): void
    {
        $this->fakeScoring($this->scoreSheet());

        foreach ([
            ['turn-0001', ['score' => 1, 'word' => 'A']],
            ['turn-0002', ['score' => 999, 'word' => str_repeat('A', 15)]],
            ['turn-0003', ['kind' => 'adjust', 'score' => 999, 'note' => str_repeat('x', 30), 'word' => '']],
            ['turn-0004', ['kind' => 'adjust', 'score' => -999, 'note' => '', 'word' => '']],
            // An id is 8 to 40 letters, digits, dashes and underscores
            ['abcd1234', []],
            [str_repeat('a', 40), []],
        ] as [$id, $turn]) {
            $this->send('add', $this->turnOf($id, $turn), false)->assertOk();
        }
    }

    // Who the turn is for

    public function test_a_turn_for_the_signed_in_player_needs_the_game_and_the_player(): void
    {
        $this->fakeScoring($this->scoreSheet());

        $this->signedIn()->withCredentials()->postJson('/game/turn', $this->turnOf('turn-0001'))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'The game and the player are needed to score']);

        $this->signedIn()->withCredentials()->postJson('/game/turn', $this->turnOf('turn-0001') + ['game_id' => 'g-1', 'player_id' => ''])
            ->assertStatus(422);

        $this->signedIn()->withCredentials()->postJson('/game/turn', $this->turnOf('turn-0001') + ['game_id' => ['g-1'], 'player_id' => 'p-1'])
            ->assertStatus(422);

        $this->assertNothingSaved();
    }

    public function test_the_turn_is_scored_for_the_game_and_player_that_were_sent(): void
    {
        $this->fakeScoring($this->scoreSheet(), [
            'api.test/v3/resource-types/rt-1/resources/r-1/items/g-2/data/p-2' => $this->byMethod([
                'GET' => Http::response(['key' => 'p-2', 'value' => $this->scoreSheet()], 200),
                'PATCH' => Http::response(null, 204),
            ]),
            $this->items('/g-2/log') => Http::response(['id' => 'log-2'], 201),
        ]);

        $this->signedIn()->withCredentials()->postJson('/game/turn', $this->turnOf('turn-0001') + ['game_id' => 'g-2', 'player_id' => 'p-2'])->assertOk();

        self::assertCount(1, $this->sentTo('PATCH', 'api.test/v3/resource-types/rt-1/resources/r-1/items/g-2/data/p-2'));
        self::assertCount(0, $this->sentTo('PATCH', self::SHEET));
        self::assertSame('p-2', json_decode($this->sentTo('POST', $this->items('/g-2/log'))[0]['parameters'], true)['player']);
    }

    public function test_a_share_link_can_only_score_for_its_own_game_and_player(): void
    {
        $this->fakeScoring($this->scoreSheet());

        $this->send('add', $this->turnOf('turn-0001') + ['game_id' => 'g-other', 'player_id' => 'p-other'], true)->assertOk();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'g-other') || str_contains($request->url(), 'p-other'));
        self::assertSame(1, count($this->savedSheet()['turns']));
    }

    public function test_an_unknown_share_link_cannot_score(): void
    {
        $this->fakeScoring($this->scoreSheet());

        foreach (['turn', 'turn/remove', 'turn/restore'] as $path) {
            $this->postJson("/public/score-sheet/not-a-token/{$path}", $this->turnOf('turn-0001'))->assertNotFound();
        }

        Http::assertNothingSent();
    }

    public function test_a_guest_cannot_score_on_the_game_screen(): void
    {
        $this->fakeScoring($this->scoreSheet());

        $this->postJson('/game/turn', $this->turnOf('turn-0001') + ['game_id' => 'g-1', 'player_id' => 'p-1'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    #[DataProvider('modes')]
    public function test_the_api_is_called_as_the_signed_in_player_or_as_the_owner_of_the_share_link(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet());

        $this->send('add', $this->turnOf('turn-0001'), $public)->assertOk();

        $expected = $public ? 'owner-bearer' : self::BEARER;

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/items/g-1/data/p-1') && $request->header('Authorization') === ['Bearer '.$expected]);
        Http::assertNotSent(fn (Request $request) => $request->header('Authorization') === ['Bearer '.($public ? self::BEARER : 'owner-bearer')]);
    }

    // The log

    public static function logMessages(): array
    {
        return [
            'a word' => [
                'add', ['id' => 'turn-0001', 'kind' => 'word', 'word' => 'quiz', 'score' => 52, 'bingo' => false],
                'Played QUIZ for 52',
                ['action' => 'add', 'turn' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'bingo' => false],
            ],
            'a word without the word' => [
                'add', ['id' => 'turn-0001', 'kind' => 'word', 'word' => '', 'score' => 25, 'bingo' => false],
                'Scored 25',
                ['action' => 'add', 'turn' => 'turn-0001', 'kind' => 'word', 'word' => '', 'score' => 25, 'bingo' => false],
            ],
            'a bingo' => [
                'add', ['id' => 'turn-0001', 'kind' => 'word', 'word' => 'retains', 'score' => 68, 'bingo' => true],
                'Played RETAINS for 118, including the 50 point bingo',
                ['action' => 'add', 'turn' => 'turn-0001', 'kind' => 'word', 'word' => 'RETAINS', 'score' => 118, 'bingo' => true],
            ],
            'a pass' => [
                'add', ['id' => 'turn-0001', 'kind' => 'pass'],
                'Passed their turn',
                ['action' => 'add', 'turn' => 'turn-0001', 'kind' => 'pass', 'word' => '', 'score' => 0, 'bingo' => false],
            ],
            'an adjustment' => [
                'add', ['id' => 'turn-0001', 'kind' => 'adjust', 'score' => -7, 'note' => 'Tiles left'],
                'Adjusted their score by -7 (Tiles left)',
                ['action' => 'add', 'turn' => 'turn-0001', 'kind' => 'adjust', 'word' => '', 'score' => -7, 'bingo' => false],
            ],
        ];
    }

    #[DataProvider('logMessages')]
    public function test_every_turn_is_logged_against_the_game(string $operation, array $turn, string $message, array $parameters): void
    {
        $this->fakeScoring($this->scoreSheet());

        $this->send($operation, $turn, false)->assertOk();

        $logged = $this->sentTo('POST', $this->items('/g-1/log'));
        self::assertCount(1, $logged);
        self::assertSame($message, $logged[0]['message']);
        self::assertSame(['player' => 'p-1'] + $parameters, json_decode($logged[0]['parameters'], true));
    }

    #[DataProvider('modes')]
    public function test_changing_removing_and_restoring_are_logged_too(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet([$this->stored('turn-0001', 'QUIZ', 52), $this->stored('turn-0002', 'FAX', 33, false, true)]));

        $this->send('change', $this->turnOf('turn-0001', ['score' => 62]), $public)->assertOk();
        $this->send('remove', ['id' => 'turn-0001'], $public)->assertOk();
        $this->send('restore', ['id' => 'turn-0002'], $public)->assertOk();

        $messages = array_map(fn (Request $request) => $request['message'], $this->sentTo('POST', $this->items('/g-1/log')));

        self::assertSame(
            [
                'Changed their turn from QUIZ for 52 to QUIZ for 62',
                'Removed their turn, QUIZ for 52',
                'Restored their turn, FAX for 33',
            ],
            $messages
        );

        $changed = json_decode($this->sentTo('POST', $this->items('/g-1/log'))[0]['parameters'], true);
        self::assertSame(52, $changed['previous']);
        self::assertSame('QUIZ', $changed['previous_word']);
        self::assertSame(62, $changed['score']);
    }

    #[DataProvider('modes')]
    public function test_a_failure_logging_the_turn_emails_the_error_address_and_the_turn_is_still_saved(bool $public): void
    {
        Notification::fake();
        $this->fakeScoring($this->scoreSheet(), [$this->items('/g-1/log') => Http::response(['message' => 'The log is down'], 503)]);

        $this->send('add', $this->turnOf('turn-0001'), $public)
            ->assertOk()
            ->assertJsonPath('message', 'Turn saved');

        self::assertSame('QUIZ', $this->savedSheet()['turns'][0]['word']);

        Notification::assertSentOnDemand(
            ApiError::class,
            fn (ApiError $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'errors@scrabble.test'
                && in_array('Error: Unable to log the turn', $notification->toMail($notifiable)->introLines, true)
                && in_array('Message: The log is down', $notification->toMail($notifiable)->introLines, true)
        );
    }

    #[DataProvider('modes')]
    public function test_nothing_is_logged_for_a_turn_that_was_not_saved(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet(), [
            self::SHEET => $this->byMethod([
                'GET' => Http::response(['key' => 'p-1', 'value' => $this->scoreSheet()], 200),
                'PATCH' => Http::response(['message' => 'The API is down'], 503),
            ]),
        ]);

        $this->send('add', $this->turnOf('turn-0001'), $public)->assertStatus(503);

        self::assertCount(0, $this->sentTo('POST', $this->items('/g-1/log')));
    }

    // When the API fails

    #[DataProvider('modes')]
    public function test_a_score_sheet_that_cannot_be_read_is_reported_to_the_browser_and_nothing_is_saved(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet(), [
            self::SHEET => $this->byMethod(['GET' => Http::response(['message' => 'Not found'], 404)]),
        ]);

        $this->send('add', $this->turnOf('turn-0001'), $public)
            ->assertNotFound()
            // No sheet: the browser is only told that it could not be read, it keeps what it has
            ->assertExactJson(['message' => 'Unable to fetch your score sheet']);

        $this->assertNothingSaved();
    }

    #[DataProvider('modes')]
    public function test_every_kind_of_change_reports_a_score_sheet_that_cannot_be_read(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet(), [
            self::SHEET => $this->byMethod(['GET' => Http::response(['message' => 'The API is down'], 503)]),
        ]);

        foreach ([
            ['add', $this->turnOf('turn-0001')],
            ['change', $this->turnOf('turn-0001')],
            ['remove', ['id' => 'turn-0001']],
            ['restore', ['id' => 'turn-0001']],
        ] as [$operation, $payload]) {
            $this->send($operation, $payload, $public)
                ->assertStatus(503)
                ->assertExactJson(['message' => 'Unable to fetch your score sheet']);
        }
    }

    #[DataProvider('modes')]
    public function test_a_score_sheet_that_cannot_be_saved_is_reported_to_the_browser(bool $public): void
    {
        $this->fakeScoring($this->scoreSheet(), [
            self::SHEET => $this->byMethod([
                'GET' => Http::response(['key' => 'p-1', 'value' => $this->scoreSheet()], 200),
                'PATCH' => Http::response(['message' => 'The API is down'], 503),
            ]),
        ]);

        $this->send('add', $this->turnOf('turn-0001'), $public)
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Failed to update your score sheet']);
    }

    #[DataProvider('modes')]
    public function test_a_sheet_the_api_stores_badly_is_read_as_far_as_it_can_be(bool $public): void
    {
        // Turns that lost keys, came back as the wrong type, and something that is not a turn at all
        $this->fakeScoring([
            'turns' => [
                ['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52],
                ['id' => 'turn-0002', 'score' => '33', 'bingo' => 'yes'],
                'junk',
            ],
        ]);

        $this->send('add', $this->turnOf('turn-0003', ['word' => 'FAX', 'score' => 33]), $public)
            ->assertOk()
            ->assertJsonCount(3, 'sheet.turns')
            ->assertJsonPath('sheet.turns.0', ['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'bingo' => false, 'note' => '', 'at' => '', 'removed' => false])
            // A score that is not a number is nothing, a bingo that is not true is not one
            ->assertJsonPath('sheet.turns.1.score', 0)
            ->assertJsonPath('sheet.turns.1.bingo', false)
            ->assertJsonPath('sheet.score.total', 85);
    }
}
