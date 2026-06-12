# kmaps-solr-sync
Utility to generate and synchronize kmaps-in-kmassets entries


# Quick Start

- Copy .env.dist to .env and customize
- run npm script:   `npm run docker-build`
- Navigate to http://localhost:3000 (default) to examine queue state.
- Trigger a reindex by sending a udp packet to port 3001 (default)
    - e.g. `echo | nc -w 0 -u localhost 3001`
    - NB: Currently the udp packet contents are ignored, but eventually parameters could be passed this way.
    
## Current Caveats April 3, 2021

- this currently is not production ready
- this will run a hardcoded query to determine which entries to synchronize based on timestamp:
    - e.g. (NOW-7DAYS)
- that time period is found around line 37 of index.js
    - if that value is changed, the whole thing needs to be rebuilt
- NB: This utility only runs one batch update per udp call.  
- NB: The time period is only determining how far back to look for changes.  It does NOT mean that it will recheck in that time period.

## TODOs April 3, 2021

- TODO: the check time period needs to be configurable
- TODO: the check time period should be passable as an parameter via the UDP call.
- TODO: other means for triggering a re-sync could/should be developed 
    - an HTTP endpoint,
    - a cron-like facillity,
    - web ui
    
    
       
