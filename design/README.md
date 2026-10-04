# Design: the look, the home page and the game screen

The look, and the screens that are Scrabble's own, for the Costs to Expect game scorers. The look is the one the Yahtzee
scorer ships with, so the apps sit together, and Carcassonne will wear it too. This is the reasoning behind it, for
whoever changes it and for the next scorer. The pages are the Blade layouts and components in
`resources/views/components`, the classes they share are in `resources/css/app.css` and the scripts are in `public/js`
(`ui.js` on every page, `turn-entry.js` and `score-sheet.js` on the game screen), `README.md` says how they fit together.

## What was decided

- **One screen runs the whole game.** Only one person has to be signed in and that person can do everything from one
  screen: see every player's turns, add a turn for any of them, undo a slip, see who is ahead and finish the game. Sharing
  is there for the players who want to add their own words on their own phone, it is never needed.
- **The home page is the Launchpad**, as it is in Yahtzee: one question, "Who's scoring?", a tile for every player, the
  next game two taps away. A tile **keeps its place**, the order the players take their turns in, a tile that moves every
  time someone scores is a tile that gets tapped by mistake. Who is ahead has a crown, who is next up has a pill.
- **A tile says what was played last**, "Last: QUIZ +52", where Yahtzee has a ring of turns played. Scrabble has no fixed
  number of turns, so there is nothing to count down to and nothing to draw a ring of.
- **A turn is a word and what it scored.** The letters are optional (people score a word and forget to type it), the score
  is not, the 50 point bingo is a toggle next to it and a pass or a tile swap is a turn that scores nothing. The tiles left
  at the end of the game are an adjustment, up or down, with a note.
- **Mistakes can be fixed.** Undo is a snackbar for six seconds, and tapping a turn later offers to change or remove it.
  Nothing is ever deleted from a stored sheet (see the README) so this is safe whatever the API does with an update.
- **The game is finished by hand**, the owner decides, the finish sheet lists where everyone is and reminds them to use
  Adjust for the tiles left first. A tie is a win for everyone on the top score.
- **The look is polished and friendly, not "gamer"**: warm paper, white cards, soft shadows, one deep teal, a friendly
  typeface, no neon, no dark theme, no sound effects. Scrabble, Yahtzee and Carcassonne share it, so nothing in it is
  about tiles or dice except the mark, which is the only thing that tells the games apart.

## The look

### Colour

| Colour | Where it is used | Rule |
|---|---|---|
| **Teal** `brand-*`, `700` is `#057176` | Buttons, links, rings, the leader's tile, the logo tile | The same for every scorer. A game is told apart by its mark and name, never its colour, so a new game never needs a new palette |
| **Costs to Expect purple** `cte-*`, `#8A1786` | The "A Costs to Expect app" footer lockup (the real logo) and the account pages | Nowhere else. The app is a Costs to Expect app, the purple is how it says so |
| **Gold** (amber) | A crown for who is ahead, a trophy for who won a finished game, the bingo | Never a warning |
| **Green, amber, red** | Saved, not saved, delete | Only ever status, always with an icon and words, teal is never one of them |
| **Players** | Six soft avatar colours (rose, sky, lime, violet, orange, slate) | A player gets theirs from their place in the players list, so they keep it in every game and nothing is stored |
| **Paper** `#F7F5F0`, stone | The page, text and borders | Warm greys, not the cold default |

**700** is the working teal for buttons, links and text on white or paper (5.8:1). **800** is the hover for 700 and the
text on the pale tints. **50 and 100** are tints for soft buttons and selected rows. **600 and lighter** are for rings,
bars and fills only, white text on 600 is 4:1.

### Type

**Figtree**, self-hosted in `public/fonts` (SIL Open Font License, latin and latin-ext so a name such as "Łukasz" does not
fall back to another font mid-word). It is friendly without being childish and it has real **tabular figures**, so a
column of scores lines up and a total never jiggles as it changes. Scores are 800 weight, headings 800, names and buttons
700, body 400 and 600. A word is set in capitals with a little letter spacing, it is what a tile looks like.

### Accessibility, measured

Every page, and every sheet while it is open (the entry sheet in each of its three states, changing a turn, finishing,
sharing, removing a player, deleting), was run through axe-core with the WCAG 2.0, 2.1 and 2.2 A and AA rules and its best
practices, signed out and signed in, at 320, 390 and 1280px wide.

- No violations. Colour contrast is decided for every pair axe can see; the few it cannot (dark text on the leader's tile
  and the landing page hero, which sit on a gradient from white to the palest teal) are dark on near white.
- One note, that is how a scrolling sheet works: on a screen that is 640px tall or less the bottom rows of the number pad
  are under the Save bar until the sheet is scrolled. The bar says what the total is ("Save 118"), so nothing is lost
  from view while the pad is scrolled.
- No page scrolls sideways at 320px.
- Touch targets on a phone: a turn row is 64px, a number pad key 56px, every other primary control 44px or more. The
  Word, Pass and Adjust switch and the adjustment presets are 40px inside a 48px control, and the footer links are smaller.
- A sheet is a native `<dialog>`: focus is trapped, Escape closes it, the page behind is inert. Focus returns to what
  opened it, and survives the list being redrawn.
- Totals are `aria-live`, toggles are `aria-pressed` or `aria-checked`, a chosen player chip shows a tick and not only a
  colour, an unsaved turn has an icon, words and a banner, a failed save never looks like a saved one.
- Animations use `motion-safe`, transitions use `motion-reduce:transition-none` and the confetti does not run for anyone
  who asks for reduced motion.

## The game screen

Everything on one page, `score-sheet.js` draws it from the data the page carries and keeps it up to date.

- **The total bar** stays in view, with the best word, the average and the bingos of the player that is open, and a pill
  that says Saving, Saved or Not saved. A turn is on the screen as soon as it is entered and saved in the background, one
  at a time and in order, a turn that could not be saved stays on the screen marked "Not saved" with a Retry, and leaving
  with one unsaved asks first.
- **Players**: a strip under the total bar on a phone, the Everyone panel beside the turns from `lg` up. Tap a player to see
  their turns. A player with a turn that has not been saved is marked in both.
- **Add a turn** is the big button, the sheet it opens is the entry (below). The home page's tiles open it for that player.
- **Turns** are a list, newest first (the newest is the one about to be corrected), each with what it was worth and a
  bingo star. Tap one to change it or take it off. A turn that is taken off leaves the list and the snackbar offers Undo,
  the stored sheet keeps it, marked removed.
- **Finished?** The Finish game section is at the bottom of the screen, and the home page and the overview of a game have a
  Finish game button. The menu in the corner has what is rarer: share links, add a player, remove a player and delete.

### The entry sheet

A bottom sheet on a phone, a centred one on a laptop. Who played (when it is not one player's own screen), then what
happened, **Word**, **Pass** or **Adjust**, one tap each.

- **Word**: the letters (optional, letters only), the score on a number pad, a Bingo toggle that adds the 50.
- **Pass**: one line that says it still ends the turn, and a button.
- **Adjust**: presets for the usual reasons (Tiles left, Went out, Penalty), a minus or plus, the points and an optional note.

The save button is at the bottom of the sheet and sticks there, so it is always in reach, and it says what is about to be
saved ("Save 118"). The number pad is the piece the three games share: it enters a Yahtzee sum, a Scrabble score and a
Carcassonne score.

## Home, games, stats

- **Home** has three states: the first visit (one box for the names), a game running, and no game running ("Play again with
  Ada, Ben & Cleo" is the whole page). Several open games are switched between with pills that say when each started.
- **Games** lists every finished game, newest first, with who won, each player's score and their best word.
- **The overview of a game** is the tiles of an open game, or for a finished one the result, **Highlights** (the highest and
  lowest word, the longest word, the average, bingos, the winning margin) and a card for each player.
- **Stats** are the records across every finished game and a line for each player, they are worked out from the finished
  games alone, see the README.

## How the next games plug in

The home page, the game screen and the entry sheet are built from the same pieces for every game. What a game changes is
`config/app/game.php` (its name, mark, the words on a tile, how many players), its mark in `app/View/Icons.php`, its rules
(`App\Support\ScoreRules`) and the panels of its entry sheet.

| Piece | Yahtzee | Scrabble | Carcassonne |
|---|---|---|---|
| Player tile | Ring for turns, "8 of 13 turns" | No ring, "Last: QUIZ +52" | No ring, "Last: city +12" |
| Tile button | Open score sheet | Add a turn | Add points |
| Players | Any number | 2 to 4 | 2 to 5 |
| The sheet | Thirteen combinations to fill | A running list of turns | A running list of scoring events, by feature |
| Entry sheet | Count chips, Score or Scratch, number pad | Number pad, optional word, +50 bingo toggle | Quick chips for the usual points, number pad |

The same for every game: the total bar, undo, the saved state, the Everyone panel, sharing, finishing, the empty states,
the footer.

## Reusing it

- `resources/css/app.css` only scans the places it lists (`source(none)`), add a path there if classes are ever built
  somewhere new. Bump `css` in `config/app/version.php` when the CSS changes and `js` when `public/js` does.
- Another scorer is a separate app, copy `resources/css/app.css`, `public/fonts`, the Blade components,
  `app/View/Icons.php` and `public/js/ui.js` and it has the look. To give one game its own accent, override
  `--color-brand-*` in that app, nothing else changes.

## Not designed yet

Dark mode, and the sign in, register and account pages beyond the shared look.
