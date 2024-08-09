#!/usr/bin/perl -w

#select 
#   distinct(main.sfoldername) as dtime,
#   main.foldername,
#   main.idnum 
#from main 
#left join foldersets on foldersets.foldername = main.foldername 
#     where main.idnum like 'YourIDNum%' && 
#           foldersets.county='YourCounty' && 
#           foldersets.doctype='YourDocType' 
#order by dtime

use strict;
use DBI;
use Time::localtime;
use IO::Handle;

my $user = '...';
my $passwd = '...';
my %sequences_pr = ();
my %sequences_dr = ();
my $MAIL_LIST = 'marcel@xxx.com,abc@xxx.com,ccc@xxx.com';
#my $MAIL_LIST = 'marcel@xyz.com';

my $day = localtime->mday();
$day = ($day < 10) ? (0 . $day) : $day;
my $month = localtime->mon() + 1;
$month = ($month < 10) ? (0 . $month) : $month;
my $today = $month . '-' . $day . '-' . (localtime->year() +1900);

my $dbh = DBI->connect('dbi:mysql:xxx', $user, $passwd, { RaiseError => 1, AutoCommit => 0 }) || die "Unable to connect to database : $DBI::errstr\n";

######
# Missing PRxxxxx
######
my $cnt_pr=0;
my $PR_MISSING_FILE = '/tmp/pr_missing_' . $today;
my @sorted_seqs = ();
my $cnt_pr_missing = 0;

my $sth = $dbh->prepare( qq{
   SELECT idnum, foldername, dtime, sfoldername, county 
   FROM main 
   WHERE idnum LIKE 'PR%' AND SUBSTR(idnum FROM 3 FOR 4)= ? 
   GROUP BY idnum
});

my $this_year = localtime->year() + 1900;
my $last_year = $this_year - 1;
my @years = ($last_year, $this_year);

foreach my $year (@years) {
   $sth->execute($year);
   my $array_ref = $sth->fetchall_arrayref( { idnum => 1, foldername => 1,dtime => 1, sfoldername => 1, county => 1 } );
   foreach my $row (@$array_ref) { 
       #print "$year\t$cnt_pr\t$row->{idnum}\t$row->{foldername}\t$row->{dtime}\t$row->{sfoldername}\t$row->{county}\n"; 
       my $match = 0;
       $match = $1 if $row->{idnum} =~ /PR(\d+)/;
       if ($match) {
          $sequences_pr{$match} = 1;
          $cnt_pr++;
       }
   } 

   @sorted_seqs = sort keys %sequences_pr;
   $cnt_pr_missing = 0;

   open(MISSING, ">>$PR_MISSING_FILE") || die "Can't open log file $PR_MISSING_FILE : $!";
   MISSING->autoflush(1);

   printf MISSING "Date : $today\t\t$cnt_pr documents($year)\t\t";
   print MISSING "Search start = $sorted_seqs[0]\t search end = $sorted_seqs[scalar(@sorted_seqs)-1]\n\n";

   if ($cnt_pr) {
      for (my $x=$sorted_seqs[0]; $x <= $sorted_seqs[scalar(@sorted_seqs)-1]; $x++) {
         print MISSING "Missing PR$x\n" if !defined $sequences_pr{$x};
         $cnt_pr_missing++ if !defined $sequences_pr{$x};
      }
   } else {
      print MISSING "Nothing's missing today.\n";
   }
   print MISSING "\n";
   %sequences_pr = ();
   close(MISSING);
}
qx{ /usr/bin/mail -s "PR::DocIndexer missing docs - $today - $cnt_pr_missing out of $cnt_pr" $MAIL_LIST < $PR_MISSING_FILE };

######
# Missing DRxxxxx
######
my $cnt_dr = 0;
my $DR_MISSING_FILE = '/tmp/dr_missing_' . $today;
@sorted_seqs = ();
my $cnt_dr_missing = 0;

$sth = $dbh->prepare( qq{
   SELECT idnum, foldername, dtime, sfoldername, county 
   FROM main 
   WHERE idnum LIKE 'DR%' AND SUBSTR(idnum,3) > ? AND SUBSTR(idnum FROM 3 FOR 4) = ? 
   GROUP BY idnum
});
foreach my $year (@years) {
   my $some_mystical_value = $year == 2009 ? 20094356 : 0;
   $sth->execute($some_mystical_value, $year);
   my $array_ref = $sth->fetchall_arrayref( { idnum => 1, foldername => 1,dtime => 1, sfoldername => 1, county => 1 } );
   foreach my $row (@$array_ref) {
       #print "$row->{idnum}\t$row->{foldername}\t$row->{dtime}\t$row->{sfoldername}\t$row->{county}\n";
       my $match = 0;
       $match = $1 if $row->{idnum} =~ /DR(\d+)/;
       if ($match) {
          $sequences_dr{$match} = 1; 
          $cnt_dr++;
       }
   }

   # check for missing and print to file

   @sorted_seqs = sort keys %sequences_dr;
   my $cnt_dr_missing = 0;
   open(MISSING, ">>$DR_MISSING_FILE") || die "Can't open log file $DR_MISSING_FILE : $!";
   MISSING->autoflush(1);

   printf MISSING "Date : $today\t\t$cnt_dr documents($year)\t\t";
   print MISSING "Search start = $sorted_seqs[0]\t search end = $sorted_seqs[scalar(@sorted_seqs)-1]\n\n";

   if ($cnt_dr) {
      for (my $x=$sorted_seqs[0]; $x <= $sorted_seqs[scalar(@sorted_seqs)-1]; $x++) {
         print MISSING "Missing DR$x\n" if !defined $sequences_dr{$x};
         $cnt_dr_missing++ if !defined $sequences_dr{$x};
      }
   } else {
      print MISSING "Nothing's missing today.\n";
   }
   print MISSING "\n";
   %sequences_dr = ();
   close(MISSING);

}
qx{ /usr/bin/mail -s "DR::DocIndexer missing docs - $today - $cnt_dr_missing out of $cnt_dr" $MAIL_LIST < $DR_MISSING_FILE };

$dbh->disconnect;

END 
{
   qx{ rm -f $PR_MISSING_FILE $DR_MISSING_FILE };
   # always exit with zero, so batch calls can be used
   exit 0;
}
