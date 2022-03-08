#!/bin/bash

#Merges latest main into the local branch. Preserves secrets in the local repository, normally should not create conflicts
git checkout main
git pull origin main
git checkout local
git merge main