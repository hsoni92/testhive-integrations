# testhive-integrations

Public **GPL** integrations that connect LMS platforms to [Testhive](https://testhiveapp.com) via **Testhive Core** (`/api/core`).

The main Testhive application is proprietary and hosted separately. This repository only contains installable LMS plugins and related connectors.

## Repository layout

| Path | Description |
|------|-------------|
| [`moodle/testhive/`](moodle/testhive/) | Moodle activity module `mod_testhive` (Moodle 4.5+) |

## Moodle (`mod_testhive`)

Teachers add a **Testhive assessment** activity to a course. The plugin schedules a test through Core, launches learners without passcode email, and syncs grades into the Moodle gradebook on a schedule.

**Site administration**

1. Install the plugin under `mod/testhive` (upload zip or clone this repo’s `moodle/testhive` folder).
2. Set **Testhive Core API base URL** (e.g. `https://your-app.example/api/core`).
3. Set **Testhive service API key** (`sk-...` from your Testhive organization).

**Packaging for install**

```bash
cd moodle
zip -r mod_testhive.zip testhive
```

Upload `mod_testhive.zip` in Moodle: *Site administration → Plugins → Install plugins*.

**Marketplace**

Listings use frankenstyle `mod_testhive`. Point the VCS URL at this repository and enable GitHub Issues for support.

## Development

This repo is linked into the private [testhive-pinnacle](https://bitbucket.org) monorepo as a **git submodule** at `integrations/` so plugin changes can be pushed here directly:

```bash
cd integrations/moodle/testhive
git pull origin main
# edit, commit, push
git push origin main
```

Then bump the submodule pointer in the monorepo when pinning a release.

## License

Plugin PHP in `moodle/testhive/` is licensed under **GPL-3.0-or-later** (see `moodle/testhive/LICENSE` and file headers). The repository root `LICENSE` applies to GPL material in this repo.
