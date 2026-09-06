# NexaBoard

A threaded discussion board for MediaWiki 1.39+.

## Features

- `Special:NexaBoard/Username` — visit any user's board
- Threaded posts with titled subjects
- Nested replies, aimed at a specific message rather than the thread
- Permalinks and visible IDs for every thread and message
- Editing of posts and replies, with an "edited" marker
- Close/reopen (a closed thread accepts no replies) and follow/unfollow
- Soft-delete with restore, for whole threads or individual messages
- Bulk delete of selected threads, or of every reply in them
- Merge threads and move messages between them, within one board
- Every moderation action is written to `Special:Log/nexaboard`
- Echo notifications for posts, replies and @mentions, deep-linked to the message
- UserProfileV2 avatar integration with letter-fallback
- Mobile responsive

## Installation

1. Copy the `NexaBoard/` directory to `extensions/NexaBoard/`
2. Add to `LocalSettings.php`:
   ```php
   wfLoadExtension( 'NexaBoard' );
   ```
3. Run the database updater:
   ```bash
   php maintenance/update.php
   ```

## Permissions

| Right | Default | Description |
|---|---|---|
| `nexaboard-post` | logged-in users | Post messages and replies |
| `nexaboard-edit-own` | logged-in users | Edit and delete own messages |
| `nexaboard-edit-others` | sysop | Edit anyone's messages |
| `nexaboard-close` | sysop | Close and reopen any thread |
| `nexaboard-delete` | sysop | Soft-delete and restore threads and messages |
| `nexaboard-merge` | sysop | Merge threads |
| `nexaboard-move` | sysop | Move messages between threads |
| `nexaboard-admin` | sysop | Full moderation access |

Board owners can additionally close, reopen and delete threads on their own board,
without holding the site-wide rights.

## Configuration

| Variable | Default | Description |
|---|---|---|
| `$wgNexaBoardAllowAnonymous` | `false` | Allow anon posting |
| `$wgNexaBoardThreadsPerPage` | `20` | Threads per page |
| `$wgNexaBoardMaxTitleLength` | `200` | Max subject length |
| `$wgNexaBoardMaxBodyLength` | `65535` | Max message length |
| `$wgNexaBoardRedirectUserTalk` | `true` | Redirect plain `User talk:` views to the board |

## UserProfileV2 Integration

If UserProfileV2 is installed, avatars are pulled automatically via
`Telepedia\UserProfileV2\Avatar\UserProfileV2Avatar`. When not installed or
when a user has no custom avatar, a colored circle with their initial is shown.

## User talk pages

By default the extension takes over `User talk:` as the entry point to a board.
A plain view of `User talk:Example` redirects to `Special:NexaBoard/Example`, and talk
links elsewhere on the wiki are rewritten to point at the board.

The redirect is deliberately narrow, so existing talk content stays reachable:

- Talk **subpages** (archives) are never redirected.
- `?action=history`, diffs and `?oldid=` are never redirected.
- Any non-view action (`edit`, `raw`, `delete`, …) is never redirected.
- `?redirect=no` bypasses it, as with any other redirect.

Set `$wgNexaBoardRedirectUserTalk = false;` to turn the takeover off entirely and
leave talk pages alone.

## Notes

- The extension works fully without UserProfileV2.
- All deletion is soft: rows are flagged, never removed, and everything the UI
  deletes can be restored.
