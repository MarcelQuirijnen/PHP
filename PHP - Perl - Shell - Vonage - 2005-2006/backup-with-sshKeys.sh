#!/bin/sh

# stardate : 05 dash 02 dash 2006
# by : MQ @ CallProc
#
# This script takes a backup of callproc software on xxx and stores it on the abcdef
# It runs as user '....' and file transfer is done without prompt for a password.
# This was done as follows :
#    1. ssh-keygen -t dsa /opt/users/..../noc-rsync-key
#       This generates a private and a public key file in the specified directory. 
#       DO NOT specify a passphrase, just press Enter. When done you'll see 2 files
#          noc-rsync-key   and   noc-rsync-key.pub
#       Make sure it is owned by '....' and not world-readable
#         -rw-------    1 noc      sa            668 May  1 20:30 noc-rsync-key
#         -rw-------    1 noc      sa            613 May  1 20:30 noc-rsync-key.pub
#    2.a. Copy the public key to the '....' home directory on the abcdef 
#    2.b. Copy the 'validate-rsync' script to the '....' home directory as well.
#         It's a wrapper script around rsync for security reasons
#         Make sure it's executable ONLY by .... 
#           chown noc.sa /opt/users/..../validate-rsync 
#           chmod u+rwx,go-rwx /opt/users/..../validate-rsync
#           -rwx------    1 noc      sa            327 May  1 20:42 /opt/users/..../validate-rsync
#    3. on abcdef, do the following
#          mkdir -p /opt/users/..../.ssh
#          cd /opt/users/....
#          cp noc-rsync-key.pub ./.ssh/authorized_keys
#          chown -R noc.sa .ssh
#          chmod u+rw,go-rwx ./.ssh/authorized_keys
#             -rw-------    1 noc      sa            674 May  1 20:43 authorized_keys
#    4. Make the key only usable for rsync requests comming from xyz as follows :
#          edit ./.ssh/authorized_keys and add
#             from="xx.xx.xx.xx",command="/opt/users/..../validate-rsync"
#          in front of the key info
#             ssh-dss AAAAB3NzaC1kc3MAA....
#          This should look like :
#             from="xx.xx.xx.xx",command="/opt/users/..../validate-rsync" ssh-dss AAAAB3N...
#    5. Use rsync with the following commandline option
#          rsync --rsh="ssh -i /opt/users/..../noc-rsync-key" ..
#    6. You're good to go.
# 
#    7. rsync errors are emailed to TheGuysThatMakeItHappenOrHaveAGoodExcuseForWhyItDoesnt
#
USERNAME=xxxxxx
PASSWD=xxxxx
DATABASE=xxx
BACKUP_TO_DIR="/opt/backup/`date +%A`"
BACKUP_TO_HOST=xx.xx.xx.xx
OPTS='-a --force --ignore-errors --backup --update'
MAIL_LIST='xxxx@vonage.com'

#
# 0. Create MySQL dump
#
d=`/bin/date +%u`
BACKUP_DIR=/opt/backup/$DATABASE/$d
if [ ! -d $BACKUP_DIR ] ; then
   /bin/mkdir -p $BACKUP_DIR
fi
for i in `echo "show tables" | mysql $DATABASE -u$USERNAME -p$PASSWD | grep -v Tables_in_`; do
  rm -f $BACKUP_DIR/$i.sql.gz
  /usr/bin/mysqldump $DATABASE --add-drop-table --allow-keywords -q -a -c -u$USERNAME -p$PASSWD $i > $BACKUP_DIR/$i.sql
  gzip $BACKUP_DIR/$i.sql
done 
/usr/bin/mysqldump -u$USERNAME -p$PASSWD --databases $DATABASE > $BACKUP_DIR/fullbackup-bsp.sql
gzip $BACKUP_DIR/fullbackup-bsp.sql

#
# 1. backup callproc scripts
#
BACKUP=/opt/configs
EXCLUDE='--exclude RTPs --exclude=locations --exclude=rpm --exclude=alerts --exclude=old --exclude=LOGGERs'
INCLUDE=''

/usr/bin/rsync --rsh="ssh -i /opt/users/..../noc-rsync-key" $OPTS $EXCLUDE $INCLUDE $BACKUP noc@$BACKUP_TO_HOST:$BACKUP_TO_DIR 2>/tmp/rsync.err

#
# 2. backup MYSQL dumps
#
BACKUP=/opt/backup/BSP
EXCLUDE=''
INCLUDE=''

/usr/bin/rsync --rsh='ssh -i /opt/users/..../noc-rsync-key' $OPTS $EXCLUDE $INCLUDE $BACKUP noc@$BACKUP_TO_HOST:$BACKUP_TO_DIR 2>>/tmp/rsync.err

#
# 3. backup callproc website
#
BACKUP=/var/www
EXCLUDE='--exclude=/var/www/dev/dev.bak --exclude=/var/www/.ssh'
INCLUDE=''

/usr/bin/rsync --rsh='ssh -i /opt/users/..../noc-rsync-key' $OPTS $EXCLUDE $INCLUDE $BACKUP noc@$BACKUP_TO_HOST:$BACKUP_TO_DIR  2>>/tmp/rsync.err

#
# 4. Backup misc files
#
BACKUP=/etc/rsyncd.conf
EXCLUDE=''
INCLUDE='--include=/etc/httpd/conf --include=/etc/xinetd.d/rsync --include=/etc/init.d/httpd'

/usr/bin/rsync --rsh='ssh -i /opt/users/..../noc-rsync-key' $OPTS $EXCLUDE $INCLUDE $BACKUP noc@$BACKUP_TO_HOST:$BACKUP_TO_DIR  2>>/tmp/rsync.err

#
# 5. Keep me posted about problems
#
if [ -s /tmp/rsync.err ] ; then
   /usr/bin/Mail -s 'Rsync problems' $MAIL_LIST </tmp/rsync.err
fi
rm -f /tmp/rsync.err
