---
title: Use your email with an AI assistant
description: Let Claude, ChatGPT, or another connected assistant read email you can see, save drafts, and send for you with a five minute window to cancel.
order: 9
updated: "2026-10-06"
related: [help/email-and-calendar/choose-who-sees-your-email, help/ai-assistant/connect-claude-or-chatgpt, help/workspace/manage-members-and-roles]
---

A connected assistant such as Claude or ChatGPT can work with your synced
email. You choose what it may do, and it never sees more than you see in
Relaticle.

Email access is off for every connection until you turn it on.

## Turn it on for a connector

1. If the assistant is already connected, open **Access Tokens** from your
   avatar menu, find it under **AI Connectors**, and click **Revoke**.
2. Add Relaticle to the assistant again. The steps are in
   [Connect Claude or ChatGPT to Relaticle](/help/ai-assistant/connect-claude-or-chatgpt).
3. On the Relaticle consent screen, pick the workspace.
4. Under **Email access**, tick what the assistant may do.
5. Click **Authorize**.

| Option | What the assistant can do |
|---|---|
| **Read your email** | Read the email you can already see in this workspace. |
| **Save email drafts** | Write a draft into your Drafts. Nothing is sent. |
| **Send email as you** | Send from a mailbox you connected, after a five minute hold. |

Leave every box empty and the assistant keeps no email access.

## Turn it on for an access token

Open **Access Tokens** from your avatar menu. Tick **Read email**,
**Draft email**, or **Send email** when you create a token. For a token you
already have, click its **Permissions** icon, change the boxes, and save.

An older token that shows **All** under **Permissions** includes email
access. Open its permissions and save only the boxes you want.

## What the assistant can read

The assistant reads an email at the sharing level that email has for you.
The level names are the ones in
[Choose who sees your email](/help/email-and-calendar/choose-who-sees-your-email).

| Sharing level | What the assistant gets |
|---|---|
| Your own email | Everything, including BCC recipients. |
| **Full access** | The sender, the To and CC recipients, the subject, the message text, and the names of attachments. |
| **Subject line and metadata** | The sender, the To recipients, the subject, and the time. |
| **Metadata only** | The sender, the To recipients, and the time. |
| **Private** | Nothing. The email does not appear. |

A very long message is cut, and the assistant is told it was cut. It never
receives the contents of an attachment.

Only email that was delivered appears. Drafts and email waiting to send do
not. A teammate's internal conversations stay hidden. Ask for the email on a
protected or blocked person or company and the assistant gets nothing back.

When an email shows less than the assistant needs, you can
[ask the teammate for more access](/help/email-and-calendar/request-access-to-an-email).

## Drafts and sending

The assistant writes plain text or markdown, and Relaticle turns it into the
email. The email carries no images and no attachments. Your mailbox's default
signature is added unless the assistant leaves it out. A draft goes into your
**Drafts**, and you review it before you send.

An email the assistant sends waits five minutes before it leaves. It sends
from a mailbox you connected yourself, never a teammate's.

1. You get a notification, such as "Claude queued an email". It names the
   subject and the recipients.
2. Click **Cancel send** in that notification to stop it.
3. You can also open the **Emails** page, then **Outbox**, then the
   **Scheduled** tab, and cancel the email there.

After five minutes the email goes out and cannot be cancelled. It then shows
in your synced email like any other.

The Owner, Admin, and Member roles can send through an assistant. A Viewer
cannot. See [Manage members and roles](/help/workspace/manage-members-and-roles).

## Turn it off

For a connector, click **Revoke** under **AI Connectors**, then connect it
again and leave the **Email access** boxes empty. For an access token, click
its **Permissions** icon and untick the email boxes.

## Before you grant sending

An assistant acts on what it reads, and email text comes from outside
senders. Grant sending only to an assistant you trust, and cancel anything
you did not ask for.
