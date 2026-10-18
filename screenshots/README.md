# Screenshots

The four PNGs in this directory are referenced from two places, and both expect these exact file
names — do not rename them without updating the references:

| file | referenced by |
|---|---|
| `1-my-articles.png` | `README.md` (the first image) and `appinfo/info.xml` (`<screenshot>`) |
| `2-editor.png` | `appinfo/info.xml` |
| `3-requests.png` | `appinfo/info.xml` |
| `4-settings.png` | `appinfo/info.xml` |

`appinfo/info.xml` points at
`https://raw.githubusercontent.com/jiuyungame/nextcloud-pages/main/screenshots/<file>`, because the App Store schema
types `<screenshot>` as a secure URL. That means the files have to be committed to `main` of the
repository named in `<repository>` — and if the repository owner or name changes, both the `<repository>`
element and those four URLs have to change with it.

## What to capture

Capture from a real instance with a real (but harmless) site. Use English UI, a light theme, a
1600 px wide window, and save as PNG. No browser chrome, no personal data, no secrets.

### 1. `1-my-articles.png` — *My articles*

The content page as an author sees it, with the list of their own pages. It must show:

- at least three rows, one per state, so the **Internal**, **Waiting for review** and **External**
  badges are all visible at the same time,
- the page titles and the modification dates (a reader has to see that this is a file list, not a
  table of settings),
- the **New article** action and the per-row actions,
- nothing from the admin settings page.

This is the image that carries the README, so it should answer "what is this app?" in one look:
*my pages, with their visibility.*

### 2. `2-editor.png` — *The editor*

One page open in the editor. It must show:

- the structured editor with the front-matter fields (title, tags, date) and the Markdown body,
- the **preview** of the same page next to it, rendered — the point of the image is that the author
  sees the built page without leaving Nextcloud,
- the save action and the page's current state (the internal/external badge).

### 3. `3-requests.png` — *Requests*

The review queue as a reviewer sees it. It must show:

- at least two pending requests, one **online** and one **offline**, with the author's name and how
  long each has been waiting,
- the decision actions (**Approve** / **Reject**) and the note field for a rejection,
- the page being decided, so it is clear that the decision is about a page and not about a user.

A screenshot of an empty queue is not useful here.

### 4. `4-settings.png` — *Settings*

*Administration settings → Pages*. It must show:

- the **9 controls**: bridge URL, pairing code (+ Pair), site storage user, Nextcloud folder, git
  remote, branch, author content directory, and the two group grants,
- the status block: the bridge version/protocol, the two secret **fingerprints** (they are hashes, not
  secrets — that is the point of showing them), the count of external pages and the sync state,
- no pairing code in the field and no secret anywhere: a fingerprint is what may appear in a
  screenshot.

## Checking them in

```bash
git add screenshots/*.png
git commit -m "screenshots: capture the four images the README and info.xml reference"
git push
```

After pushing, open `appinfo/info.xml` on GitHub and check that the four raw URLs resolve (the App
Store reads them from there).
