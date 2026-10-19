-- Migration 005: Help articles
--
-- Adds the Help_Article_Store — the `help_articles` table that backs the
-- top-level Help Centre module (`/help-centre.php`). Each row is one help
-- article: a URL-safe `slug`, display `title`, short `summary`, search
-- `keywords`, a `topic` used to group articles on the index, a publication
-- `status`, a `sort_order` within the topic, and a `body` holding the
-- Rich_Body as a JSON array of ordered content blocks (paragraphs, headings,
-- step lists and action blocks).
--
-- Design notes:
--   * `CREATE TABLE IF NOT EXISTS` keeps the migration idempotent — re-running
--     it leaves an existing table untouched rather than failing. Seed content
--     (added in later statements) uses slug-guarded inserts for the same
--     reason.
--   * `slug` is UNIQUE so each article has one stable Article_Viewer URL and a
--     duplicate seed insert is rejected.
--   * The composite index on (status, topic, sort_order) supports the grouped
--     "published articles by topic" listing query the Help_Centre_Index runs.
--   * `body` is stored as JSON so the renderer can walk ordered, typed blocks.

CREATE TABLE IF NOT EXISTS help_articles (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(160)    NOT NULL,
    title       VARCHAR(255)    NOT NULL,
    summary     VARCHAR(500)    NOT NULL DEFAULT '',
    keywords    VARCHAR(500)    NOT NULL DEFAULT '',
    body        JSON            NOT NULL,
    topic       VARCHAR(120)    NOT NULL DEFAULT '',
    status      ENUM('draft','published') NOT NULL DEFAULT 'draft',
    sort_order  INT             NOT NULL DEFAULT 0,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_articles_slug (slug),
    KEY idx_help_articles_status_topic (status, topic, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ===========================================================================
-- Seed content — explicit-topic articles (Req 5.1)
--
-- Each article is seeded with a slug-guarded INSERT ... SELECT ... FROM DUAL
-- WHERE NOT EXISTS so re-running the migration never duplicates a row and
-- never fails on an already-seeded article (Req 1.13, 5.9).
--
-- `body` holds the Rich_Body as a JSON array of ordered blocks:
--   { "type":"paragraph", "text":"..." }
--   { "type":"heading", "level":2, "text":"..." }
--   { "type":"steps", "items":["...","..."] }
--   { "type":"action", "actions":[ { "label":"...", "url":"...",
--                                    "external":false, "style":"primary" } ] }
-- Single quotes/apostrophes inside the JSON are doubled ('') because the whole
-- JSON document is a single-quoted SQL string literal.
--
-- sort_order values are spaced (10, 20, 30, ...) within each topic to leave
-- room for future inserts. Every article is status = 'published' (Req 5.7).
-- ===========================================================================

-- 1. adding-new-members → /dc/add-user.php (People & Access) ----------------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'adding-new-members',
    'Adding new members to the District App',
    'Create an account for a new leader or volunteer so they can sign in and use the District App.',
    'add member user account new leader volunteer invite onboard people access create',
    '[
      {"type":"paragraph","text":"When a new leader or volunteer joins, you can give them access to the District App by creating an account for them. Once added, they can sign in with Microsoft and see the tools relevant to their role."},
      {"type":"heading","level":2,"text":"Before you start"},
      {"type":"paragraph","text":"You will need the person''s full name, their email address, and the group they belong to. Make sure you have permission to add members for that group."},
      {"type":"heading","level":2,"text":"Add a member"},
      {"type":"steps","items":[
        "Open the Add User page from the button below.",
        "Enter the person''s first name, last name and email address.",
        "Choose the group they belong to and the role or access level they need.",
        "Check the details are correct, then save to create the account.",
        "Let the new member know they can now sign in with their Microsoft account."
      ]},
      {"type":"paragraph","text":"The new member appears in your group straight away. You can edit their details later if anything changes."},
      {"type":"action","actions":[
        {"label":"Add a new member","url":"/dc/add-user.php","external":false,"style":"primary"}
      ]}
    ]',
    'People & Access',
    'published',
    10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'adding-new-members');

-- 2. adding-an-event → /dc/add-event.php (Events) ---------------------------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'adding-an-event',
    'Adding an event to the calendar',
    'Create a new event on the District Calendar so your group can see and plan for it.',
    'add event calendar create new activity meeting camp booking date',
    '[
      {"type":"paragraph","text":"Adding an event puts it on the District Calendar so everyone in your group can see what is happening and when."},
      {"type":"heading","level":2,"text":"Add an event"},
      {"type":"steps","items":[
        "Open the Add Event page using the button below.",
        "Give the event a clear title so people recognise it at a glance.",
        "Set the start and end date and time.",
        "Add the location and any details leaders need to know.",
        "Attach a risk assessment or booking link if the event requires one.",
        "Save the event to publish it to the calendar."
      ]},
      {"type":"paragraph","text":"Once saved, the event shows on the calendar for your group. You can edit it later if plans change."},
      {"type":"action","actions":[
        {"label":"Add an event","url":"/dc/add-event.php","external":false,"style":"primary"}
      ]}
    ]',
    'Events',
    'published',
    10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'adding-an-event');

-- 3. searching-risk-assessments → /dc/risk-assessments.php
--    + /dc/download-risk-assessment.php (Risk Assessments) ------------------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'searching-risk-assessments',
    'Searching risk assessments',
    'Find and download an existing risk assessment so you can reuse or review it.',
    'risk assessment search find download document safety activity review reuse',
    '[
      {"type":"paragraph","text":"Risk assessments are stored in one place so you can find, review and reuse them without starting from scratch."},
      {"type":"heading","level":2,"text":"Find a risk assessment"},
      {"type":"steps","items":[
        "Open the Risk Assessments page using the button below.",
        "Type a keyword from the title or activity into the search box.",
        "Scan the results to find the assessment you need.",
        "Open it to review the details."
      ]},
      {"type":"heading","level":2,"text":"Download a copy"},
      {"type":"steps","items":[
        "Find the risk assessment you want in the list.",
        "Use the download button to save a copy to your device.",
        "Review or adapt it for your own activity as needed."
      ]},
      {"type":"action","actions":[
        {"label":"Search risk assessments","url":"/dc/risk-assessments.php","external":false,"style":"primary"},
        {"label":"Download a risk assessment","url":"/dc/download-risk-assessment.php","external":false,"style":"secondary"}
      ]}
    ]',
    'Risk Assessments',
    'published',
    10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'searching-risk-assessments');

-- 4. editing-events → /dc/manage-event.php + /dc/event.php (Events) ---------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'editing-events',
    'Editing an event',
    'Update the details of an event you have already added to the calendar.',
    'edit event change update manage amend reschedule calendar details',
    '[
      {"type":"paragraph","text":"Plans change. You can update an event you have already created so the calendar always shows the latest details."},
      {"type":"heading","level":2,"text":"Edit an event"},
      {"type":"steps","items":[
        "Open the event from the calendar, or use the Manage Events page using the button below.",
        "Find the event you want to change.",
        "Open it and update the title, date, time, location or details as needed.",
        "Save your changes to update the calendar."
      ]},
      {"type":"paragraph","text":"Anyone viewing the event on the calendar will see the updated details straight away."},
      {"type":"action","actions":[
        {"label":"Manage events","url":"/dc/manage-event.php","external":false,"style":"primary"},
        {"label":"View an event","url":"/dc/event.php","external":false,"style":"secondary"}
      ]}
    ]',
    'Events',
    'published',
    20
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'editing-events');

-- 5. approving-events → /dc/reviewer/ (Events) ------------------------------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'approving-events',
    'Approving events',
    'Review events submitted for approval and approve or send them back as a reviewer.',
    'approve event review reviewer sign off accept reject pending approval',
    '[
      {"type":"paragraph","text":"If you are a reviewer, events submitted by leaders come to you to check before they are confirmed. Approving an event marks it as ready to go ahead."},
      {"type":"heading","level":2,"text":"Review and approve"},
      {"type":"steps","items":[
        "Open the Reviewer area using the button below.",
        "Look through the list of events waiting for approval.",
        "Open an event and check the details, dates and any attached risk assessment.",
        "Approve the event if everything is in order, or send it back with a note if something needs changing."
      ]},
      {"type":"paragraph","text":"Once approved, the event is confirmed on the calendar. If you send it back, the leader can make the changes and resubmit it."},
      {"type":"action","actions":[
        {"label":"Open the reviewer area","url":"/dc/reviewer/","external":false,"style":"primary"}
      ]}
    ]',
    'Events',
    'published',
    30
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'approving-events');

-- 6. updating-your-profile → /profile.php (People & Access) -----------------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'updating-your-profile',
    'Updating your profile',
    'Keep your own contact details and profile information up to date.',
    'profile update edit details contact photo personal information account me',
    '[
      {"type":"paragraph","text":"Your profile holds your contact details and other information other leaders may need. Keeping it up to date helps people reach you and keeps District records accurate."},
      {"type":"heading","level":2,"text":"Update your details"},
      {"type":"steps","items":[
        "Open your profile using the button below.",
        "Review your name, contact details and any other information shown.",
        "Update anything that has changed.",
        "Save your changes."
      ]},
      {"type":"paragraph","text":"Your updated details are used across the District App wherever your profile is shown."},
      {"type":"action","actions":[
        {"label":"Go to your profile","url":"/profile.php","external":false,"style":"primary"}
      ]}
    ]',
    'People & Access',
    'published',
    20
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'updating-your-profile');

-- 7. creating-a-district-email → /group-manager.php (Email & Microsoft 365) -
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'creating-a-district-email',
    'Creating a District email for someone',
    'Set up a District Microsoft 365 email account for a leader or volunteer in your group.',
    'email create district microsoft 365 account mailbox leader provision group manager',
    '[
      {"type":"paragraph","text":"Leaders and volunteers can be given a District Microsoft 365 email address. This gives them an official mailbox for District communications and access to shared tools."},
      {"type":"heading","level":2,"text":"Create an email account"},
      {"type":"steps","items":[
        "Open the Group Manager using the button below.",
        "Find the person who needs a District email in your group.",
        "Choose the option to create or provision a Microsoft 365 email for them.",
        "Confirm the details and submit the request.",
        "Share the new address and sign-in instructions with the person once it is ready."
      ]},
      {"type":"paragraph","text":"Setting up the mailbox can take a little while to complete behind the scenes. The person will be able to sign in once it is provisioned."},
      {"type":"action","actions":[
        {"label":"Open the Group Manager","url":"/group-manager.php","external":false,"style":"primary"}
      ]}
    ]',
    'Email & Microsoft 365',
    'published',
    10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'creating-a-district-email');

-- 8. using-a-shared-mailbox → https://outlook.cloud.microsoft/ (external) ----
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'using-a-shared-mailbox',
    'Using a shared mailbox',
    'Open and send email from a shared District mailbox in Outlook on the web.',
    'shared mailbox outlook microsoft 365 email open send access web district',
    '[
      {"type":"paragraph","text":"A shared mailbox lets several leaders read and send email from one District address, so messages are not tied to a single person. You open it through Outlook on the web with your own Microsoft sign-in."},
      {"type":"heading","level":2,"text":"Open a shared mailbox"},
      {"type":"steps","items":[
        "Open Outlook on the web using the button below and sign in with your District Microsoft account.",
        "Select your account picture or name in the top corner.",
        "Choose the option to open another mailbox.",
        "Type the shared mailbox address and open it.",
        "The shared mailbox opens in a new tab alongside your own inbox."
      ]},
      {"type":"heading","level":2,"text":"Send from the shared mailbox"},
      {"type":"steps","items":[
        "Start a new message from within the shared mailbox.",
        "Check the From address shows the shared mailbox, not your personal address.",
        "Write and send your message as normal."
      ]},
      {"type":"paragraph","text":"If you cannot see the shared mailbox, ask whoever manages your group to confirm you have been given access to it."},
      {"type":"action","actions":[
        {"label":"Open Outlook on the web","url":"https://outlook.cloud.microsoft/","external":true,"style":"primary"}
      ]}
    ]',
    'Email & Microsoft 365',
    'published',
    20
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'using-a-shared-mailbox');

-- 9. why-a-shared-mailbox → https://outlook.cloud.microsoft/ (external) ------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'why-a-shared-mailbox',
    'Why the District uses a shared mailbox',
    'Understand why District roles use a shared mailbox instead of a personal email address.',
    'shared mailbox why reason continuity handover role email district explanation outlook',
    '[
      {"type":"paragraph","text":"The District uses shared mailboxes for key roles rather than personal email addresses. This keeps District communication consistent and does not depend on any one person."},
      {"type":"heading","level":2,"text":"The main reasons"},
      {"type":"steps","items":[
        "Continuity: when a leader moves on, the mailbox and its history stay with the role rather than leaving with the person.",
        "Shared visibility: more than one leader can keep an eye on messages, so nothing is missed if someone is away.",
        "A consistent address: contacts always reach the role at the same address, even as the people in the role change.",
        "Clear separation: District business stays separate from a leader''s personal email."
      ]},
      {"type":"paragraph","text":"When you need to send on behalf of a role, open the shared mailbox in Outlook on the web and send from there so the message comes from the role address."},
      {"type":"action","actions":[
        {"label":"Open Outlook on the web","url":"https://outlook.cloud.microsoft/","external":true,"style":"primary"}
      ]}
    ]',
    'Email & Microsoft 365',
    'published',
    30
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'why-a-shared-mailbox');
-- ===========================================================================
-- Seed content — additional articles (Req 5.2)
--
-- Slugs 10–14 from the design's Seed Content Plan. Same idempotent,
-- slug-guarded INSERT ... SELECT ... FROM DUAL WHERE NOT EXISTS pattern and
-- same Rich_Body JSON block rules as the explicit-topic set above. sort_order
-- values continue the existing per-topic spacing (Events 10/20/30 → 40;
-- People & Access 10/20 → 30/40; Email & Microsoft 365 10/20/30 → 40; the new
-- Getting Help topic starts at 10).
-- ===========================================================================

-- 10. navigating-the-calendar → /dc/event.php (Events) ----------------------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'navigating-the-calendar',
    'Viewing and navigating the District Calendar',
    'Find your way around the District Calendar and open an event to see its full details.',
    'calendar navigate view events browse month dates find event district schedule',
    '[
      {"type":"paragraph","text":"The District Calendar shows the events happening across the District. Knowing how to move around it helps you find what you need quickly and check the details of any event."},
      {"type":"heading","level":2,"text":"Move around the calendar"},
      {"type":"steps","items":[
        "Open the calendar to see the events coming up.",
        "Use the controls to move between months or dates and find the period you are interested in.",
        "Look for the event you want — events are shown on the day they take place."
      ]},
      {"type":"heading","level":2,"text":"Open an event"},
      {"type":"steps","items":[
        "Select an event to open its full details using the button below.",
        "Read the title, date, time, location and any notes for leaders.",
        "Check for an attached risk assessment or booking link if the event has one."
      ]},
      {"type":"paragraph","text":"From an event you can see everything you need to plan for it. If you organise the event, you can also edit its details from here."},
      {"type":"action","actions":[
        {"label":"View an event","url":"/dc/event.php","external":false,"style":"primary"}
      ]}
    ]',
    'Events',
    'published',
    40
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'navigating-the-calendar');

-- 11. finding-a-glv → /group-manager.php (People & Access) ------------------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'finding-a-glv',
    'Finding and contacting a Group Lead Volunteer',
    'Look up the Group Lead Volunteer for a group and find their contact details.',
    'group lead volunteer glv find contact leader people access group manager details who',
    '[
      {"type":"paragraph","text":"Every group has a Group Lead Volunteer who leads it. If you need to reach the right person about a group, you can find the Group Lead Volunteer and their contact details in the Group Manager."},
      {"type":"heading","level":2,"text":"Find a Group Lead Volunteer"},
      {"type":"steps","items":[
        "Open the Group Manager using the button below.",
        "Find the group you are interested in.",
        "Look for the person listed as the Group Lead Volunteer for that group.",
        "Open their details to see how to contact them."
      ]},
      {"type":"paragraph","text":"Once you have their contact details you can get in touch with them directly about anything relating to their group."},
      {"type":"action","actions":[
        {"label":"Open the Group Manager","url":"/group-manager.php","external":false,"style":"primary"}
      ]}
    ]',
    'People & Access',
    'published',
    30
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'finding-a-glv');

-- 12. requesting-support → /technical-support.php (Getting Help) ------------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'requesting-support',
    'Requesting technical support',
    'Report a problem or ask for help with the District App using the technical support page.',
    'support help technical problem issue report request contact bug fault assistance',
    '[
      {"type":"paragraph","text":"If something in the District App is not working as expected, or you are not sure how to do something, you can ask for help through the technical support page."},
      {"type":"heading","level":2,"text":"Request support"},
      {"type":"steps","items":[
        "Open the technical support page using the button below.",
        "Describe the problem or question as clearly as you can.",
        "Include what you were trying to do and anything that went wrong, such as an error message.",
        "Submit your request."
      ]},
      {"type":"paragraph","text":"Giving plenty of detail helps whoever picks up your request understand and fix the problem faster."},
      {"type":"action","actions":[
        {"label":"Request technical support","url":"/technical-support.php","external":false,"style":"primary"}
      ]}
    ]',
    'Getting Help',
    'published',
    10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'requesting-support');

-- 13. access-levels-and-roles → /group-manager.php (People & Access) --------
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'access-levels-and-roles',
    'Understanding access levels and roles',
    'Learn what the different access levels and roles mean and where to manage them.',
    'access levels roles permissions role leader reviewer admin group manager people who can',
    '[
      {"type":"paragraph","text":"People in the District App have different roles, and each role comes with its own level of access. This decides what someone can see and do, so the right people have the right tools without seeing things they do not need."},
      {"type":"heading","level":2,"text":"How roles and access work"},
      {"type":"steps","items":[
        "A person''s role reflects what they do in the District — for example leading a group or reviewing events.",
        "Their access level follows from their role and controls which pages and actions they can use.",
        "Giving someone a role gives them the access that goes with it, so you do not set permissions one by one.",
        "If someone needs more or less access, change their role or group membership rather than editing individual permissions."
      ]},
      {"type":"heading","level":2,"text":"Manage roles and access"},
      {"type":"steps","items":[
        "Open the Group Manager using the button below.",
        "Find the person whose role or access you want to review.",
        "Check or update their role and the group they belong to.",
        "Save any changes so their access updates."
      ]},
      {"type":"paragraph","text":"If you are not sure which role someone should have, check with whoever manages access for your group before changing it."},
      {"type":"action","actions":[
        {"label":"Open the Group Manager","url":"/group-manager.php","external":false,"style":"primary"}
      ]}
    ]',
    'People & Access',
    'published',
    40
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'access-levels-and-roles');

-- 14. logging-in-with-microsoft → https://outlook.cloud.microsoft/ (external)
INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
SELECT
    'logging-in-with-microsoft',
    'Logging in via Microsoft',
    'Sign in to the District App and Microsoft 365 using your District Microsoft account.',
    'login log in sign in microsoft 365 account password outlook authenticate access signin',
    '[
      {"type":"paragraph","text":"The District App uses your Microsoft account to sign you in. The same account also gives you access to District email and Microsoft 365, so you only need to remember one set of sign-in details."},
      {"type":"heading","level":2,"text":"Sign in"},
      {"type":"steps","items":[
        "Choose the option to sign in with Microsoft.",
        "Enter your District Microsoft email address.",
        "Enter your password, and complete any extra security check if you are asked to.",
        "Once signed in, you are taken to the District App and can open your tools."
      ]},
      {"type":"heading","level":2,"text":"If you cannot sign in"},
      {"type":"paragraph","text":"If your password is not working, use the forgotten-password option on the Microsoft sign-in screen to reset it. If you still cannot get in, ask whoever manages your group to check your account."},
      {"type":"paragraph","text":"You can check your Microsoft sign-in works by opening Outlook on the web with the same account."},
      {"type":"action","actions":[
        {"label":"Open Outlook on the web","url":"https://outlook.cloud.microsoft/","external":true,"style":"primary"}
      ]}
    ]',
    'Email & Microsoft 365',
    'published',
    40
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = 'logging-in-with-microsoft');
