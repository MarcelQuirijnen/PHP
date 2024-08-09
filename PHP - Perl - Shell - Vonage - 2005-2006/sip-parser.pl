#!/usr/bin/perl -w
use strict;
use Getopt::Long;
use vars qw($callid $all $help $fromto);

my $PROGNAME = "siplog-calltrace";

Getopt::Long::Configure('bundling');
GetOptions("c=s"  => \$callid, "call=s" => \$callid, "id=s" => \$callid, "callid=s" => \$callid,
           "h"    => \$help,   "help"   => \$help,
           "ft=s" => \$fromto, 
           "all"  => \$all,    "a"      => \$all );

if (not $all and not $callid and not $fromto) {
        print "pipe atlas logs to this script to print out sip messages\n";
        print "\tUse -h for this help message.\n";
        print "\tUse -c to search for messages with a certain callid.\n";
        print "\tUse --ft to search both from and to fields.\n";
        print "\tUse --all to dump all sip messages.\n";
        exit 0;
} 

if ($ENV{_} ne "/bin/nice"){
        print "This program must be used with the nice utility.\n";
        print "\texecute 'nice -10 sip-parser' instead.\n";
        exit 1;
}

# print only those lines between SIP Log.....
my $sip = 0;            # boolean, are we in a sip message in the log?
my $message = "";       # string, used as a buffer for sip messages
while(<>){
        # if we're not in a sip message, check to see if one's starting
        #       if so, add it to message buffer and goto next loop iteration
        #       if not, goto next loop iteration
        if (not $sip) {
                if (/^Message Log.*?<sipmsg/o) {
                        $sip = 1;
                        $message .= $_;
                        next;
                }
                next;
        }
        # pre: we're in a sip message which is located in $message buffer
        # check to see if we're at the end of a sip message
        #       if yes, print it if we want it and goto next loop iteration
        if (/<\/sipmsg>/o) {
                $sip = 0;
                if ($fromto) { 
                        print $message if ($message =~ m/^(From:|To:) .*?$fromto.*?$/om);
                        $message = "";
                        next;
                        }
                if ($callid) { 
                        print $message if ($message =~ m/^Call-ID: .*?$callid.*?$/om);
                        $message = "";
                        next;
                        }
                print $message;
                $message = "";
                next;
        }
        #pre: we're in a sip message but not at it's end
        #add it to the sip $message buffer and goto next loop iteration
        $message .= $_;
}

exit 0;