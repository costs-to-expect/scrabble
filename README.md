[![Minimum PHP Version](https://img.shields.io/badge/php-^8.2-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/costs-to-expect/scrabble/blob/main/LICENSE)
[![Tests](https://github.com/costs-to-expect/scrabble/actions/workflows/tests.yml/badge.svg)](https://github.com/costs-to-expect/scrabble/actions/workflows/tests.yml)

# Scrabble Game Scoring

## Overview

Game scoring for Scrabble, powered by the Costs to Expect API.

![Score sheet](/resources/art/score-sheet.png)

Keep the score of a game of Scrabble for two to four players from one screen. A turn is a word and what it scored,
the 50 point bingo for playing all seven tiles is a tap, and a pass or a tile swap is a turn that scores nothing. At the
end of the game an adjustment takes the tiles left on each rack off that player's score, and adds them for whoever went
out. Everyone's turns are on the same screen, so the person with the phone can keep the whole game, and if the players
want to, each of them has a link to add their own words on their own phone.

A game of Scrabble has no fixed number of turns, it ends when the bag is empty and someone has played out, so **a game is
finished by hand** when you are ready. Finishing keeps every player's numbers with the game, which is what the stats
read: the highest and the lowest scoring word, the longest word, bingos, the closest game, who is winning.

There are no local users, the app signs players in with the API and keeps their bearer token in a cookie. Everything
else, players, games and score sheets, is stored in the API, the app's own database only holds sessions, the queue,
the public score sheet links and registrations that are waiting for a password.

## Other Apps

[Yahtzee](https://github.com/costs-to-expect/yahtzee), [Yatzy](https://github.com/costs-to-expect/yatzy)

We plan to create Apps for each of the Board and dice games we play, the Apps will all be Open Source, you 
are free to create your own and then submit a PR to the Costs to Expect [API](https://github.com/costs-to-expect/api) 
to add the new game type.

## Set up

I'm going to assume you are using Docker, if not, you should be able to work out what you need to run for your 
development setup.

Go to the project root directory and run the below.

### Environment

* $ `docker network create costs.network` *
* $ `cp .env.example .env` and set the empty values, see **Configuration** below
* $ `docker compose build`
* $ `docker compose up -d`
* $ `docker exec scrabble.app composer install`
* $ `docker exec scrabble.app php artisan key:generate`

After generating the key, you need to restart your containers, so run down and up again to force the new key to be used.

* $ `docker exec scrabble.app php artisan migrate`
* $ `docker exec scrabble.app php artisan queue:work`

The queue worker sends the emails (create password, forgot password, account deletion), leave it running while you 
develop, nothing is sent without it.

*We include a network for local development purposes, I need to connect to a local version of the Costs to Expect
API, You probably don't need this so remove the network section from your docker compose file and don't create the
network.

Composer is part of the app image, run it with `docker exec scrabble.app composer ...` so dependencies are always
installed with the PHP the app runs on.

### Configuration

| Variable | Purpose |
|---|---|
| `API_URL` | The Costs to Expect API |
| `APP_DEV`, `API_URL_DEV` | Set `APP_DEV=true` to use `API_URL_DEV` instead, for a local copy of the API |
| `ITEM_TYPE_ID`, `ITEM_SUBTYPE_ID` | The API's `games` item type and its Scrabble item subtype (`.env.example` has both) |
| `COSTS_TO_EXPECT_INTERNAL_API_KEY` | See below |
| `SESSION_NAME_USER`, `SESSION_NAME_BEARER` | Names of the cookies that hold the player's id and bearer token |
| `ERROR_EMAIL` | Where failed API calls, such as a turn that could not be logged, are reported |
| `SCORE_CORRECTIONS` | `true` (the default) lets a turn be undone, changed or taken off, `false` locks every turn once it has been saved, see below |

**The internal API key.** The API only lets its own trusted apps register an account and request a password reset, 
both return a token which the app emails, so they are protected by an `X-Internal-Api-Key` header. Set 
`COSTS_TO_EXPECT_INTERNAL_API_KEY` to the same value as `INTERNAL_API_KEY` in the API's `.env`. Without it, 
registering and forgot password both fail with "This route can only be called by a trusted internal service", 
nothing else needs it.

## Testing

```bash
docker exec scrabble.app composer test
```

The tests use an in-memory SQLite database and fake every request to the API, they never touch the development 
database (the test case refuses to run against anything else) or a real API. `phpunit.xml` sets everything they 
use, including a throwaway application key, so they need no `.env`.

GitHub Actions runs them on PHP 8.2, 8.3, 8.4 and 8.5 for every push and pull request, see 
`.github/workflows/tests.yml`. 8.2 is the version the app runs on, the others are the versions it is moving to.

## Frontend assets

CSS is compiled with the standalone Tailwind CLI, there is no Node or yarn involved.

```bash
bin/css          # one-off build
bin/css --watch  # rebuild on change
```

The first run downloads the right binary for your machine into `bin/`. The output goes to 
`public/css/{version}/app.css`, the version is the `css` value in `config/app/version.php`, bump it when 
the CSS changes so deployed apps don't serve stale files, bump `js` when `public/js` changes. Commit the compiled file,
the server does not build it.

Every page is built from the Blade layouts and components in `resources/views/components` (the layouts, the icon
sprite, the avatar, the sheet, the fields and alerts, the player tiles), the classes the pages share (buttons, cards,
form controls) are in `resources/css/app.css`, and the scripts are plain JavaScript, no build step: `public/js/ui.js` is
on every page (sheets, the snackbar, confetti, copy a link), `public/js/turn-entry.js` is the sheet a turn is entered
in, `public/js/score-sheet.js` draws the game screen and `public/js/landing.js` is the score sheet to try and the
walkthrough on the landing page. Only the places listed in `resources/css/app.css` are scanned for classes, add a path
there if classes are ever built somewhere new. The reasoning behind the look, the colours and the typeface is in
[design](design/README.md).

What the pages say about the game (its name, mark, words and the number of players) is in `config/app/game.php` and the
marks are in `app/View/Icons.php`, a sibling scorer (Yahtzee, Carcassonne) copies the theme and the components and
changes those.

## Score sheets

A score sheet is stored in the API as the whole sheet, a player's turns in the order they were played and the totals:

```json
{
  "turns": [
    {"id": "t9c41f07ab2e6d35812", "kind": "word", "word": "QUIZ", "score": 52, "bingo": false, "note": "", "at": "2026-10-04T19:30:00Z", "removed": false}
  ],
  "score": {"total": 52, "turns": 1, "words": 1, "bingos": 0, "best": 52, "lowest": 52}
}
```

A turn is a `word` (the letters are optional, the score is not), a `pass` (a tile swap scores nothing either) or an
`adjust` (the tiles left at the end, a penalty after a challenge, up or down). A bingo is 50 points on top of the score
of the word, an adjustment is points but not a turn. The app reads the sheet, adds, changes or marks a turn, works out
the totals and writes it back (`App\Actions\Game\ChangeTurn`, the rules are in `App\Support\ScoreRules` and the script has
the same limits, they are written into the page). The server decides what is allowed, the browser is only asked nicely.

**Undo, change and remove are on** (`SCORE_CORRECTIONS=true`), a slip typing a score on a phone is the usual one, and
unlike a scorecard there is no combination to check a score against. They are safe because **nothing is ever taken out
of a stored sheet**. Removing a turn marks it `removed` and it stays on the sheet (that is how Undo puts it back), every turn is
always written in full, so a correction works whether the API replaces the sheet it is sent or merges it into the one it
has stored, which is the thing that made the Yahtzee scorer ship them switched off. Set `SCORE_CORRECTIONS=false` to
lock every turn once it has been saved, the server then refuses to change or remove one.

A turn has an id the browser makes up, so a save that is sent twice (the answer got lost and the browser tries again)
is only ever scored once, and a turn id that has been used for something else is refused rather than overwritten.

## Always read live

Every read from the API sends `X-Skip-Cache`, the header is added to that request alone. A game screen that has been
open for an hour and a phone that has just joined have to agree, and a cached score sheet would let one overwrite the
other. The API's cache is for the apps that read a lot and write little.

## Share links

Every player in a game has a public link that lets anyone who has it score for that player, it lives until the game is
finished or deleted. The link is a token that the app maps back to the game, the player and the **owner's bearer
token**, so the parameters are encrypted with `APP_KEY` in the `share_token` table (`App\Casts\EncryptedParameters`). Someone
who can read the table but not the key learns nothing. Changing `APP_KEY` makes the links of games in progress
unreadable, so change it between games. A link stops working when the owner signs out (the API revokes the token).

A link can add, change, remove and put back turns for **that player only**, it is the link's game and player that are
scored for, never whatever the browser sends. The page shows everyone's scores and what each of them played last, not
their whole list of turns.

## Stats

Finishing a game stores each player's numbers with it (`App\Actions\Game\Complete`, worked out by `App\Support\Stats`):
the score, the turns, the words, passes and bingos, the best, lowest and longest word, what the words came to and what
the adjustments did. The games list, the overview of a game and the stats page are all worked out from the list of
finished games, no score sheet is read again, the stats page reads the games a hundred at a time (the thousand most
recent). A game that ends level is a win for everyone on the top score.

## PHP and Laravel versions

The app runs on Laravel 12 and PHP 8.2, `composer.json` pins the platform to 8.2 so a `composer update` on a newer 
PHP on your machine can't pick packages the production server can't run. When the app moves to PHP 8.4 or later,
change the Dockerfile image, `require.php` and `config.platform.php` together, Laravel 13 needs PHP 8.3 or later.
The tests run on PHP 8.2 to 8.5 in CI.
