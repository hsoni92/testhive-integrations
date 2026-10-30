# mod_testhive

Moodle activity module that integrates with **Testhive Core**.

## Requirements

- Moodle **4.5** or later (`$plugin->requires` in `version.php`)
- Outbound HTTPS from the Moodle server to your Testhive Core base URL
- Testhive organization **service API key** with access to question sets and tests

## Configuration

*Site administration → Plugins → Activity modules → Testhive assessment*

| Setting | Example |
|---------|---------|
| API base URL | `https://testhiveapp.com/api/core` |
| Service API key | `sk-...` |

## Teacher workflow

1. In the activity chooser, open **Assessment** and add **Testhive assessment**.
2. Choose a question set (loaded from `GET /v1/questionsets`).
3. Set the open and close times and the grade maximum.
4. Enrolled students open the activity and choose **Start assessment** while the window is open.

## Grade sync

Scheduled task `mod_testhive\task\sync_grades` (default every 15 minutes) pulls `GET /v1/tests/{id}/results`, scales scores to the activity maximum, and updates the gradebook. Teachers can also choose **Sync grades now** on the activity page. Learners are matched by `externalId` (Moodle user id) when present.

Saving an activity again updates the linked Testhive test (question set, window, proctoring, instructions, invitees) when the same `externalRef` is used.

## Privacy

Learner email and Moodle user id are sent to the configured Testhive host. See `classes/privacy/provider.php`.

## Support

Open an issue in the [testhive-integrations](https://github.com/hsoni92/testhive-integrations) repository.
