# Session Log: Spike 13 S6 Alt Formats

**Date:** 2026-11-10  
**Participants:** Than Grove, Claude Code  
**Outcome:** [Spike 13 S6](../spikes/spike-13-texts-whole-book.md) in progress: (a) raw text and (b) PubReader match D7; (c) Voyant not yet tested (a free ngrok tunnel's warning page blocked Voyant's fetch; next try cloudflared, steps in the spike doc). Solo session. Work stays on the pushed throwaway branch `spike/13-texts-whole-book-s1`, so the startup check's git-sync and config:status gates fail by design until the spike ends.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-11-10 16:36

ok do the session start ritual. This is a solo session.

---

## Claude — 2026-11-10 16:37

I ran the startup checks. Two of them failed, and both failures come from the branch you're on, not from a real problem.

**Failures, both expected:**
- **Git sync:** you're on `spike/13-texts-whole-book-s1`, the local-only throwaway branch (5 "never merge" wip commits). It has no upstream, so the pull couldn't run. `origin/main` moved 14 commits since you last pulled. I haven't switched branches or pulled.
- **config:status:** the drift is all Spike 13 throwaway state: `book`, the `footnote` and `spike13_fn` formats, the `spike13_*` migrations, the book group relationship and the group-role diffs. The local DB also has 1,383 book nodes that dev-0 doesn't. All of this belongs to the spike, not to real drift.

**Passes:** `config/sync` matches `origin/main`. The node-id spot-check matched all 25 pairs. The content count differences are small and expected.

**New on main since your last pull:**
- #399 / #400: the S5 results and the 11-09 session log are merged.
- #403: Yuji decided the go/no-go threshold for #290. The 10-15 join-cost measurements pass, so the threshold is no longer open. What's left on #290 is the resolver swap (T2 phase 7), which still waits on T5. It's Yuji's issue, so there's nothing for you to do there.
- #401 / #402: a UTF-8 regression test for solr-proxy and a note on the `shanti_general_solr_query()` check.
- #391: DDEV Node 18 → 22.
- No PRs are open.

**Deferred issues:** nothing new is assigned to you. Four are unassigned: #382, #380, #357 and #300. #386 (the ADR 020 review) is still open, so the Sources gate still holds.

**Next on your queue:** Spike 13 S6 (alternate formats), then the TX3 ADR.

**Before work, two choices for you:**
1. **To stay on the spike branch for S6:** I'll leave git and the DB alone and treat the config drift as known spike state.
2. **To start on clean main:** I'll check out main and fast-forward it. Putting the DB back in line would mean restoring the `pre-spike13-s1-2026-11-02` snapshot or running `update-db-from-remote.sh dev`, and either one throws away the local Spike 13 state, so I'd confirm with you first.

Which do you want?

---

## User — 2026-11-10 17:14

Stay on the spike branch for now, if that's going to work, and if Spike 13 S5 is done, let's start on S6.

---

## Claude — 2026-11-10 17:15

I found the D7 code for all three formats. Next I'm reading how it builds them so I can rebuild them on D11.

---

## Claude — 2026-11-10 17:17

curl reaches D7 directly for this endpoint, so I don't need the browser. Fetching the other two books and diffing:

---

## Claude — 2026-11-10 17:19

Raw text is close but not identical: 97–99.6% word-level similarity. The differences fall into a few classes. I'm checking each one before calling it a pass or a fail:

---

## Claude — 2026-11-10 17:23

The diff classifier is running in the background, since a word-level diff of 200k words is slow. Meanwhile I'm tracing where the `&nbsp;` difference comes from:

---

## Claude — 2026-11-10 17:24

While that runs, I'm comparing stored D7 and D11 markup at one of the run-together spots:

---

## Claude — 2026-11-10 17:26

zsh's `set --` didn't split the pair. Rewriting this as a small script:

---

## Claude — 2026-11-10 17:27

The remaining differences fall into four classes. One possible order difference needs checking against S4's tie finding:

---

## Claude — 2026-11-10 17:27

Template ported with no Drupal 7 calls left in it. Next, the controller method and route:

---

## Claude — 2026-11-10 17:28

D7 matches D11: 11 Contents entries (the top-level chapters plus "Done") and 374 sections. I'm checking that the entry text and page count agree as well:

---

## Claude — 2026-11-10 17:28

S6 has three parts. Raw text and PubReader both pass. Voyant can't be tested yet: you need to pick how to give it a public URL (question at the end).

**(a) Raw text passes.** The new route is `/spike13/text/{nid}` and uses D7's exact transform (collapse whitespace, then strip tags). I compared it word by word against the live D7 `node_ajax_text` for books 15256, 14158 and 14679. The text matches except for four things:
- **Tie order:** in 14158 one section sits in a different place. That's the S4 sibling tie already settled in TX3 (normalize).
- **KMaps popover text:** D7's raw text includes popover junk like "Qinghai places 431 For more information about this term…", because D7's KMaps filter writes it inline. D11 has no such filter yet, so its text is cleaner, which is better for Voyant.
- **Spacing:** in a few spots D11 separates words that D7 runs together, such as "Paris" plus an email address. D11 is the more correct one.
- **Script and `&nbsp;`:** one inline `<script>` in 15256 strips differently, and there's about one `&nbsp;` per book.

One fix was needed along the way: D11 was gluing the last word of each page onto the next title. D7's templates put newlines between the section elements, and I've added the same to the spike render.

**(b) PubReader passes.** The route is `/spike13/pubreader/{nid}`. I copied D7's NCBI PubReader3 library and template into the spike module and serve the page outside the theme, as D7 did. In Chrome, the 374-page book matches D7: 374 sections, the same 11 Contents entries, the same section ids, and no console errors. The other two books return 200 but I only checked them with curl.
- The reported page count differs (D11 "Page 1 of 289", D7 "Page 1 of 457"). The count depends on the rendered content, so this is probably the popover markup and images. I haven't confirmed it, and acceptance doesn't require it.
- **Open for TX3:** like D7, PubReader drops a page the viewer can't see along with all its children, while S3's whole-book render keeps the children. One rule should apply to both.
- **Not checked:** an access question about the D7 raw-text route came up. *(Detail redacted from the public log; ask Than.)*

**(c) Voyant is blocked: it needs a URL its servers can reach.** Local DDEV isn't public, and dev-0 doesn't have the spike module (and I don't act on dev-0). Three options:
1. **Tunnel:** `ddev share` opens a temporary public ngrok tunnel to your local site. That's outward-facing, so it's your call.
2. **Test on dev-0 yourself:** deploy the route there and run the hand-off. That's heavier for a throwaway spike.
3. **Record it as a blocker:** settle Voyant in TX3. Its input will be the raw-text route that just passed. The real question for TX3 is whether the WAF lets Voyant's server fetch dev-0 or staging. D7 raw text answered curl without a challenge today, which is a good sign.

Nothing is committed yet. The changes are in `spike13_render`: the controller, the routing file, the copied PubReader library (2.3 MB) and the template. Which Voyant option do you want? After that I'll write up S6 in the spike doc.

---

## User — 2026-11-10 19:44

Give me a NID to test the rout in (a)

---

## Claude — 2026-11-10 19:44

Use book 15641 (D11 nid 123746, 52 pages). It's small and fast:

https://mandala.ddev.site/spike13/text/123746

If you're not logged in you'll get 42 of the 52 pages, because S3 set 10 of them private. To compare against D7's live output for the same book, open https://texts.mandala.library.virginia.edu/shanti_texts/node_ajax_text/15641.

Other books I compared, with no access changes:
- **14679 (374 pages):** https://mandala.ddev.site/spike13/text/123372
- **15256 (326 pages):** https://mandala.ddev.site/spike13/text/124111
- **14158 (294 pages, Tibetan):** https://mandala.ddev.site/spike13/text/123817

Locally the page starts with PHP deprecation notices from `field_group`. That's DDEV printing errors to the screen, not part of the route's output.

---

## User — 2026-11-10 19:57

Ok a and b check out for Voyant, let's try the Tunnel #1, that's ok.

---

## Claude — 2026-11-10 19:57

ngrok is only a shell alias, which DDEV won't see, so I'm putting it on PATH for this one run and starting the tunnel in the background:

---

## Claude — 2026-11-10 19:58

The tunnel didn't start because ngrok isn't actually installed. Your shell alias points to `/Users/ndg8f/Applications/ngrok`, and that file doesn't exist.

There are two ways forward:
1. **cloudflared (the easier one):** `brew install cloudflared`, then `ddev share --provider=cloudflared`. It opens a temporary `*.trycloudflare.com` URL and needs no account. I can do both steps if you say yes.
2. **ngrok:** reinstall it and run `ngrok config add-authtoken …` with your own ngrok account. You'd need to do that part yourself, since it uses your account token. After that I can start the tunnel.

Should I install cloudflared and use that?

---

## User — 2026-11-10 21:44

Ok I installed ngrok with homebrew.

---

## Claude — 2026-11-10 21:44

ngrok is installed at `/opt/homebrew/bin/ngrok`, but it has no config yet, so there's no authtoken. ngrok won't open a tunnel without one, and I can't set it for you because it's your account credential.

To set it up, run this in your own Terminal rather than with `!` here, so the token stays out of this conversation:

```
/opt/homebrew/bin/ngrok config add-authtoken <your-token>
```

The token is on https://dashboard.ngrok.com/get-started/your-authtoken.

Your `~/.zshrc` still aliases `ngrok` to the missing `~/Applications/ngrok`. That doesn't affect DDEV, which finds the Homebrew binary on its own, but you might want to delete the alias. Tell me once the token is set and I'll start the tunnel and run the Voyant hand-off.

---

## User — 2026-11-10 21:48

I removed the alias from my .zprofile but ngrok in my terminal still goes to the application location

---

## Claude — 2026-11-10 21:48

Your startup files are clean now: none of `~/.zshrc`, `~/.zprofile`, `~/.zshenv` or `~/.zlogin` mention ngrok. The terminal you have open still has the alias loaded because it read the old `.zprofile` when it started. Either of these fixes it:

```
unalias ngrok      # just this terminal
```

or open a new Terminal window. Then `which ngrok` should print `/opt/homebrew/bin/ngrok`, and you can run `ngrok config add-authtoken <your-token>`.

---

## User — 2026-11-10 21:49

ok token is set, start the tunnel

---

## Claude — 2026-11-10 21:50

Voyant built a corpus but reports "1 document with 0 total words". Checking the ngrok log for what Voyant's server actually got:

---

## Claude — 2026-11-10 21:51

The Voyant test failed, but because of the free ngrok plan, not our route, and the tunnel is now stopped. Voyant built its corpus, then reported "1 document with 0 total words".

**Why:** on free domains ngrok shows a "You are about to visit" warning page (ERR_NGROK_6024) instead of the real page whenever the request looks like it comes from a browser. A client can only skip it by sending a special header, and Voyant doesn't. I reproduced it:
- With curl or a Java User-Agent, the tunnel returned the real 391 KB raw text.
- With a Chrome User-Agent, it returned the 2.8 KB warning page.

ngrok's request log showed none of Voyant's requests ever reached DDEV, so Voyant must have gotten the warning page and found no text in it. Voyant's actual User-Agent isn't visible to me.

Screenshot of the empty Voyant result: `/var/folders/px/rrz5x77d1ndgwcy9k76p4klr0000gq/T/claude-chrome-screenshots-zB8gXp/screenshot-1794347441370-0.jpg`

**Next step:** a cloudflared quick tunnel doesn't show a warning page and needs no account. The steps would be `brew install cloudflared`, then `ddev share --provider=cloudflared`, then the same Voyant hand-off. Should I go ahead? If not, I'll record Voyant as untested over a tunnel and leave the real fetch check for when the route reaches dev-0 or staging.

One thing to fix either way: the raw text Voyant fetches locally starts with PHP deprecation notices from `field_group`, because DDEV prints errors to the screen. Voyant would count those words too. For the test I'd turn off error display on just this route, which is a spike-only change. It won't affect dev-0, which doesn't print errors to the page.

---

## User — 2026-11-10 21:53

Ok write up memory, session log, where we are, and next steps for Voyant. Remember that the sync test will fail and that we are on a spike branch for now. Commit and push everything no PR yet.

---

## Claude — 2026-11-10 21:53

Spike branch pushed. Pushes to non-main branches don't trigger deploys. Next, the docs worktree:

---

## Claude — 2026-11-10 21:54

Spike doc and spikes README are updated. Now the session log:

---
