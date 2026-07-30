---
name: larabug-issue-triage
description: Use when investigating a production error, exception, JavaScript error, failing queue job or dependency vulnerability that LaraBug may already have recorded, or when the user asks what is breaking, what is erroring, or why something failed in production. Explains how to reach the recorded issue and its stack trace through the LaraBug MCP server rather than guessing from source.
metadata:
  author: LaraBug
  tags:
    - error-tracking
    - debugging
    - triage
    - larabug
---

# LaraBug Issue Triage

## When to use this skill

Use it when the question is about something that already went wrong in a running application: "why is checkout failing", "what is erroring in production", "did that deploy break anything", "what vulnerabilities do we have". LaraBug has the exception, its stack trace and the request that caused it. Read that before reasoning from source.

To configure the package or change what it reports, use the `larabug-development` skill instead.

## Reaching the data

This needs the LaraBug MCP server connected. Check for tools named `list_projects`, `list_issues` and `get_issue` before assuming it is absent.

If it is not connected, tell the user to add it once, then continue:

```
claude mcp add --transport http larabug https://www.larabug.com/mcp
```

It authorizes over OAuth, so no token needs pasting. Reading needs the `read` scope, and changing an issue needs `write`.

Without the MCP, do not guess at what production is doing. Say the data is not reachable and ask the user to paste the issue, or to connect the server.

## The path through the tools

Work from the account down to the single occurrence. Each step narrows the next.

1. `list_projects` to find the project, or `get_project_summary` when you already know it and want the current shape: what is failing, how much, and how recently.
2. `list_issues` to see the grouped problems. An issue is the group; the exceptions inside it are the occurrences.
3. `get_issue` for one issue's detail: how often it fires, when it started, and whether anyone has touched it.
4. `get_latest_occurrence` for the part that actually resolves the bug, the stack trace and the request context that produced it.
5. `list_vulnerabilities` for a project's CVE findings, which are recorded as issues too.

Do not stop at `list_issues`. The issue title names the exception class, which is rarely enough to fix anything. `get_latest_occurrence` is where the file, the line and the request live.

## Reading an occurrence

Anchor on the stack frame inside the application rather than the topmost frame, which is usually vendor code. Then read the request context: the route, the method and the user tell you which path reached it, and that is normally what distinguishes the failing case from the working one.

Cross-check what you read against the current source before proposing a fix. An occurrence is a record of the code as it was deployed, and the line numbers drift once the file has been edited.

Sensitive values arrive redacted by design. A masked field is not a bug in the report, and it is not something to work around.

## Changing an issue

`update_issue_status` and `set_issue_note` write to the user's account and need the `write` scope.

Treat them as the user's call. Closing an issue because a fix has been written is a judgement about whether the fix works, so propose it and let the user decide, unless they have already told you to close things as you go. A note recording what you found is the safer of the two and is usually welcome.

## Reporting back

Give the user the issue and the evidence, not a summary of the tool output. What broke, where in the application, what the triggering request had in common, how often it fires and since when. Then the fix.

If several issues share a root cause, say so once rather than walking through each of them.
