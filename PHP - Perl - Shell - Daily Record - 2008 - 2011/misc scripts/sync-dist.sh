#!/bin/sh

MAIL=/usr/bin/Mail

rsync -rltgoD --numeric-ids --recursive --update -e "ssh -i /root/.ssh/id_dsa" --exclude='200[45678]*' --exclude 'Thumbs.db' abc@xx.xx.xx.xx:/array/home/docindexer/di/dist/ /data/docindexer/di/dist

if [ "$?" -eq "0" ]; then
   echo ' ' | $MAIL -s 'new docindexer -dist- folder synced : OK' admins
fi
