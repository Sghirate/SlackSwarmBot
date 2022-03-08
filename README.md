# SlackSwarmBot
Based on:  
https://www.perforce.com/manuals/swarm/Content/Swarm/extending.example_slack.html#Example_Slack_module  
This Swarm module will send messages to a Slack channel whenever a review is created/changed/committed. The messages are threaded (one Thread per Review) and the bot will attempt to impersonate the users acting on the review.

When the Listener detects a swarm event relating to a review it will first make sure that a thread for the review exists on Slack. Afterwards it will post an update message to the thread.
If the event can be drectly associated with one user the bot will impersonate that user.
If the event is 'significant enough' (such as requesting further review, updating files, or committing the review) any participant in the review will be tagged in Slack.

In order to cache the Review=>Thread association and Swarm=>Slack user mapping the module uses the **data/cache/slack** folder. Meaning: If you delete that folder the bot will re-create threads if already existing reviews change!

The module has been tested with Swarm 2021. and the January 2022 version of the Slack API. Different versions of Swarm or Slack may not immediately work!

## Installation
* Create a Swarm App
  * Go to https://api.slack.com/apps/ and Create a new app
  * Select "OAuth & Permissions" and make sure the app has at-least the following permissions:  
    chat:write, chat:write:customize, users:read, users:read.email, users.profile:read
  * Add the app to your workspace. This will give you a Bot OAuth Token, starting with 'xoxb-2', we will need this later on
  * We also need the id of the channel the bot is suppose to write to.  
    The easiest way is to open Slack, select a channel and copy a link to it. The last portion of that link, starting with a 'C' is the Channel ID we need.

In the following steps `<SwarmRoot>` refers to the folder you installed Swarm to. On Linux this would be: `/opt/perforce/swarm`
* Copy the contents of the repository (except README.md, LICENSE and .gitattributes) to `<SwarmRoot>/module/Slack/` (so that `Module.php` is located at `<SwarmRoot>/module/Slack/Module.php`)
* Edit or create `<SwarmRoot>/config/custom.modules.config.php`. Make sure it contains an entry for the Slack module.  
If you created the file it should look like this:
```
<?php
\Zend\Loader\AutoloaderFactory::factory(
    array(
        'Zend\Loader\StandardAutoloader' => array(
            'namespaces' => array(
                'Slack'      => BASE_PATH . '/module/Slack/src',
            )
        )
    )
);
return [
    'Slack'
];
```
* Edit `<SwarmRoot>/module/Slack/config.module.config.php`.
  At the bottom you find the fields we need to edit.
  * Set 'swarm_host' to the address your users use to access swarm (for example: `https://swarm.cyberdyne-systems.com/`)
  * Set 'bot_token' to the Bot OAuth Token we got above (starting with 'xoxb-2')
  * Set 'channel' to the Channel ID we got above (starting with 'C')
* Restart Swarm (for example: 'sudo service apache2 restart')

You can also modify which events the bot reacts to and which actions will trigger user mentions by editing `<SwarmRoot>/module/Slack/src/Listener/SlackActivityListener.php`. At the top of the file you find 3 arrays (EVENTS_TO_HANDLE, ACTIONS_TO_MENTION, ACTIONS_TO_IGNORE); Feel free to change them according to your needs and restart the Swarm server afterwards.


## Darewise specific
* You will find this module in `/opt/perforce/swarm/module/Slack`.
* We maintain a branch on the swarm server where the configuration contains the necessary secrets
* In order to update the bot, we pull `main` from the repository and merge with the `local` branch
* SSH in the swarm server and run `update.sh` to update. Check for any conflicts and make sure to commit into the `local` branch.