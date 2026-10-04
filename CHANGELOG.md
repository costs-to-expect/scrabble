# Changelog

The complete changelog for the Costs to Expect Scrabble game scorer, our changelog follows the format defined at https://keepachangelog.com/en/1.0.0/

## [1.0.0] - [2026-10-04]
### Added
- The Scrabble game scorer, powered by the Costs to Expect API. It is built on the foundations of the Costs to Expect
  Yahtzee scorer and looks the same: players are the API's categories, games are its items and score sheets are the
  data of a game, the app's own database only holds sessions, the queue, the public share links and the registrations
  that are waiting for a password. Sign in, register, forgot password, the account pages and deleting an account work as
  they do in Yahtzee.
- The game screen. The person keeping the score runs the whole game from one screen: every player's turns, the entry for
  a new one, the total and everyone's scores always in view (a strip of players on a phone, an Everyone panel beside the
  turns on a laptop, both update by themselves). Tap a player to see their turns and add one for them.
- Turns. A turn is a word and what it scored (the letters are optional, the score is not), a pass (or a tile swap, it
  scores nothing either way) or an adjustment, up or down, with a short note, for the tiles left on the racks at the end
  of the game or a penalty after a challenge. A bingo is a tap, it adds the 50 points to the score of the word. The entry
  sheet has a number pad, a word box that only takes letters and presets for the usual adjustments. `public/js/turn-entry.js`.
- A turn is on the screen straight away and saved in the background, one at a time and in the order they were entered.
  A pill says Saving, Saved or Not saved, a failed save keeps its turn on the screen with a Retry, nothing is lost and
  leaving the page with a turn that is not saved asks first. A turn has an id the browser makes up, so a save that is
  sent again is only ever scored once.
- Undo, change and remove a turn (Undo puts a removed turn back). They are on (`SCORE_CORRECTIONS`) because nothing is
  ever taken out of a stored score sheet: a removed turn stays on it marked removed and every turn is always written in
  full, so they work whether the API replaces the sheet it is sent or merges it into the one it has stored. Set
  `SCORE_CORRECTIONS=false` to lock every turn once it is saved. See the README.
- The server checks every turn: the kind has to exist, a word is letters only and no longer than the board is wide, a
  word scores 1 to 999, a pass scores nothing, an adjustment is up to 999 either way, a note is 30 characters at most
  and a score sheet holds 150 turns.
- Games are finished by hand, Scrabble has no fixed number of turns. Finishing stores every player's numbers with the game
  (their score, turns, words, passes, bingos, best, lowest and longest word and what the adjustments came to) and removes
  the share links. A game that ends level is a win for everyone on the top score. Finish and play again starts the next game with
  the same players.
- Two to four players in a game. The home page and the game tiles keep the players in the order they play in, whoever has
  played the fewest turns is next up, the one who is ahead has a crown and the tile of each player shows the last thing
  they played.
- Share links, one for every player, so the players who want to can add their own words on their own phone. The link
  scores for that player only and shows everyone's scores and what each of them played last. The owner's bearer token is
  stored encrypted with the application key.
- Stats. The highest and the lowest scoring word, the longest word, the highest game score, the closest game, the biggest
  win, the lowest winning score, the most bingos in a game, and a line for every player (games, wins, average game, best
  game, best word, average word and bingos). They are worked out from the finished games alone, a game keeps its players'
  numbers, no score sheet is read again.
- The overview of a finished game, who won, the highlights of the game and a card for every player.
- A landing page with a score sheet to try (the real entry sheet, nothing is sent anywhere), "How a game night goes", and
  a closing call to register.
- The home page: "Who's scoring?", a tile for every player and the next game two taps away. Play again with the players of
  the last game in one tap, several open games switched between on one page, and how long a game has been running.
- Tests, they use an in-memory SQLite database and fake every request to the API, `composer test`. GitHub Actions runs
  them on PHP 8.2, 8.3, 8.4 and 8.5 for every push and pull request.
- Tailwind CSS v4 through the standalone CLI (`bin/css`), with the teal theme and the Figtree typeface, self-hosted in
  `public/fonts`. Costs to Expect purple is kept for the footer and the account pages. See `design/README.md`.
- Every read from the API skips its cache, for that request only. A game screen that has been open for an hour and a phone
  that has just joined have to agree, a cached score sheet would let one overwrite the other.
- Signing out revokes the bearer token in the API, as well as forgetting the cookies, and so does deleting an account,
  the queued deletion jobs are encrypted because they carry the token.
- Removing a player from a game, finishing a game and deleting a game are POSTs behind a confirmation, a link or a
  prefetch can never do them. Typing the same name twice when starting the first game is one player, whatever the case.
