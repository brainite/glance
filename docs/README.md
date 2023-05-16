# Glance

Static dashboard reports for GitHub issues

**No longer maintained.**
After using glance reliably for 9 years, we transitioned to a new system in 2023.

## Basic Usage

```sh
# GitHub token stored in YAML
/bin/glance update --conf=[path-to-configuration.yml]
# GitHub token NOT stored in YAML
/bin/glance update --conf=[path-to-configuration.yml] --token=[github_token]
```

## Basic Configuration

<!-- {% raw %} -->
```yaml
# Default settings allow quick configuration of multiple dashboards.
# Each team member can have separate dashboards with unique prioritization logic.
defaults:
  # Generate token at https://github.com/settings/tokens
  token:      GITHUB_TOKEN
  
  # Basics: Choose a repo and specify open issues.
  repos:      ["brainite/glance-example"]
  state:      open
  filter:     is:open
  
  # Debug a specific issue number
  # debug:      99
  
  # Weight the issues based on which filters they match.
  # Start with a weight of 1, and then multiply by each filter that matches.
  # Thus, setting a filter's weight to 0 would make any matches be 0/hidden. 
  weights:
    # Bugs: bold, add label
    - filter: label:bug
      weight: 100
      suffix: "__ __(BUG)__"
      prefix: "__"
    # Overdue: add label
    # This is based on a line in the issue formatted: "Due: YYYY-MM-DD"
    - filter: due:"* .. -1 day"
      weight: 20
      suffix: " __(overdue)__"
    # Due today: add label 
    - filter: due:"today .. today"
      weight: 10
      suffix: " __(due TODAY)__"
    # Due next 4 days: add due date
    - filter: due:"+1 day .. +4 days"
      weight: 5
      suffix: " __(due _{{due}}_)__"
    # Due next week: add due date
    - filter: due:"+5 days .. +11 days"
      weight: 2
      suffix: " _(due {{due}})_"
    # Due 46+ days: hide
    - filter: due:"+46 days .. *"
      weight: 0
    # Unassigned: Move to top for correction
    - filter: no:assignee
      weight: 1000
      suffix: " __(no assignee)__"
      assignee: =owner
    # No milestone: Move to top for correction
    - filter: no:milestone
      weight: 1000
      suffix: " __(no milestone)__"
    # Prioritize with 10+ comments
    - filter: comments:>10
      weight: 1.5
    # Prioritize only when in the current month.
    # Thus, the label "idle-10" would hide an issue until October.
    - filter: label:idle-{{month}}
      weight: 10
    - filter: label:idle-{{month_1}}
      weight: 0
    - filter: label:idle-{{month_2}}
      weight: 0
    - filter: label:idle-{{month_3}}
      weight: 0
    - filter: label:idle-{{month_4}}
      weight: 0
    - filter: label:idle-{{month_5}}
      weight: 0
    - filter: label:idle-{{month_6}}
      weight: 0
    - filter: label:idle-{{month_7}}
      weight: 0
    - filter: label:idle-{{month_8}}
      weight: 0
    - filter: label:idle-{{month_9}}
      weight: 0
    - filter: label:idle-{{month_10}}
      weight: 0
    - filter: label:idle-{{month_11}}
      weight: 0
    # Prioritize a milestone and move under a heading
    - filter: milestone:"Beta"
      weight: 5
      heading: "Beta Site"
  
  
# Configure a dashboard that extends the default settings.
dashboard:
  # Markup to precede the list.
  header: |
    Glance Issue Tracking
    ===
    
  output:
    # The output does NOT need to be the same repo.
    # Best practice: the output should go in a repo
    #   without any code due to the high number of commits.
    repo:   brainite/glance-example-output
    branch: master
    path:   README.md

# Extend a dashboard to easily align dashboards.
# Differentiate the dashboard using inherit_filter
dashboard_sub:
  inherit_from: dashboard
  
  # Only show issues for one user
  inherit_filter: assignee:brainite
  header: |
    John Doe's Priority Issues
    ===
  output:
    repo:   brainite/glance-example-output
    branch: master
    path:   Users/JohnDoe/README.md


```
<!-- {% endraw %} -->
