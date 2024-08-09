#!/usr/bin/perl -w

# Create the docindexer table used by this script
#   CREATE TABLE IF NOT EXISTS flags (
#      scriptname VARCHAR(20) COMMENT 'script to run',
#      folderset VARCHAR(25) NOT NULL DEFAULT '',
#      started TIMESTAMP default 0,
#      ended TIMESTAMP default 0,
#      resultfile VARCHAR(50) COMMENT 'where the results go'
#   );

use strict;
use DBI;
use Time::localtime;
use IO::Handle;
use File::Basename;
use Mail::Sender;

my $user = '...';
my $passwd = '...';
my $MAIL_LIST = 'marcel@xyz.com,abc@xyz.com';
#my $MAIL_LIST = 'marcel@xyz.com';
my $dbh;

# list of background/evening tasks, started by docindexer
my %DocIndexerTasks = (
   # scriptname       => subroutine to be executed
   'pulaski_re_ov.pl' => \&Pulaski_re_ov,             # Audit tab
);

# Format local date
my $day = localtime->mday();
$day = ($day < 10) ? (0 . $day) : $day;
my $month = localtime->mon() + 1;
$month = ($month < 10) ? (0 . $month) : $month;
my $today = $month . '-' . $day . '-' . (localtime->year() +1900);


sub Pulaski_re_ov
{
   my ($resultfile, $scriptname, $set) = @_;
   my ($folderset, $range_start, $range_end, $prev_range_end) = ('','','','');

   # open HTML file
   open(RESULTFILE, "+>$resultfile") || die "Can't open file $resultfile : $!";
   RESULTFILE->autoflush(1);
   # open TXT file
   open(RESULTFILE_TXT, "+>$resultfile.txt") || die "Can't open file $resultfile.txt : $!";
   RESULTFILE_TXT->autoflush(1);

   # Mark the start 
   my $tm = localtime;
   my ($HOURS, $MIN) = ($tm->hour, $tm->min);

   # write html markup
   print RESULTFILE qq{
<html>
<head>
<title>Pulaski Realestate folderset list</title>
<STYLE type="text/css">
   .y2000 { background-color: BurlyWood; }
   .y2001 { background-color: AliceBlue; }
   .y2002 { background-color: Gold; }
   .y2003 { background-color: Aquamarine; }
   .y2004 { background-color: Chartreuse; }
   .y2005 { background-color: BlueViolet; }
   .y2006 { background-color: DarkGoldenRod; }
   .y2007 { background-color: Darkorange; }
   .y2008 { background-color: Coral; }
   .y2009 { background-color: DarkKhaki; }
   .y2010 { background-color: DarkSalmon; }
   .y2011 { background-color: Bisque; }
</STYLE>
</head>
<body>
};

   print RESULTFILE "<h2>$today $HOURS:$MIN : started.</h2><br>\n";
   print RESULTFILE_TXT "$today $HOURS:$MIN : started.</h2>\n\n";

   my $sth = $dbh->prepare("UPDATE flags SET started = now() WHERE go = 1 AND scriptname = ?");
   $sth->execute($scriptname) or die $dbh->errstr;

   # Create TMP table, table will be destroyed when connection is closed
   $dbh->do(qq{
      CREATE TEMPORARY TABLE IF NOT EXISTS results (
         folderset VARCHAR(25) NOT NULL, 
         foldername VARCHAR(25) NOT NULL, 
         filename VARCHAR(35) NOT NULL,
         idnum VARCHAR(12) NOT NULL
      )
   });
  
   # Clear table if it exists 
   # $dbh->do(qq{ TRUNCATE TABLE results });
   
   # Fill up TMP table
   if ($set eq 'All') {
      $dbh->do(qq{
         INSERT INTO results(folderset, foldername, filename, idnum)
            SELECT DISTINCT(foldersets.folderset), foldersets.foldername, IF(main.filename IS NULL, 'empty', main.filename) AS filename, main.idnum
            FROM foldersets 
            LEFT JOIN main ON foldersets.foldername = main.foldername 
            WHERE foldersets.county='pulaski' AND foldersets.doctype='realestate' 
            ORDER BY main.idnum
      });
   } else {
      $dbh->do(q{
         INSERT INTO results(folderset, foldername, filename)
            SELECT DISTINCT(foldersets.folderset), foldersets.foldername, IF(main.filename IS NULL, 'empty', main.filename) AS filename, main.idnum
            FROM foldersets
            LEFT JOIN main ON foldersets.foldername = main.foldername
            WHERE foldersets.county='pulaski' AND foldersets.doctype='realestate' AND foldersets.folderset = ?
            ORDER BY main.idnum
         }, undef, $set) or die $dbh->errstr;
   }

   # For each distinct folderset, get each distinct foldername and for each of those foldernames, get a list of filenames
   # Sort the filenames alphabetically and represent first and last record as a range

   $sth = $dbh->prepare(qq{ SELECT DISTINCT folderset FROM results ORDER BY folderset });
   $sth->execute() || die $dbh->errstr;
   my $folderset_ref = $sth->fetchall_arrayref( { folderset => 1 } );
   foreach my $folderset (@$folderset_ref) {
      my $sth2 = $dbh->prepare(qq{ SELECT idnum FROM results WHERE folderset = ? ORDER BY idnum });
      $sth2->execute($folderset->{folderset}) || die $dbh->errstr;
      my $doc_refs = $sth2->fetchall_arrayref();
      my @docs = @$doc_refs;
      $sth2->finish;

      print RESULTFILE_TXT "Folderset : $folderset->{folderset}\nDocrange : $docs[0]->[0] - $docs[scalar(@docs)-1]->[0]\n";

      my $class = '';
      $class = 'y' . $2 if $folderset->{folderset} =~ /^(\d{4})(\d{4})$/;
      #if ($folderset->{folderset} =~ /^(\d{4})(\d{4})$/) {
      #   CASE : {
      #     if ($2 =~ /^2000/)  { $class = 'y2000'; last CASE; }
      #     if ($2 =~ /^2001/)  { $class = 'y2001'; last CASE; }
      #     if ($2 =~ /^2002/)  { $class = 'y2002'; last CASE; }
      #     if ($2 =~ /^2003/)  { $class = 'y2003'; last CASE; }
      #     if ($2 =~ /^2004/)  { $class = 'y2004'; last CASE; }
      #     if ($2 =~ /^2005/)  { $class = 'y2005'; last CASE; }
      #     if ($2 =~ /^2006/)  { $class = 'y2006'; last CASE; }
      #     if ($2 =~ /^2007/)  { $class = 'y2007'; last CASE; }
      #     if ($2 =~ /^2008/)  { $class = 'y2008'; last CASE; }
      #     if ($2 =~ /^2009/)  { $class = 'y2009'; last CASE; }
      #     if ($2 =~ /^2010/)  { $class = 'y2010'; last CASE; }
      #     if ($2 =~ /^2011/)  { $class = 'y2011'; last CASE; }
      #   }
      #}
      print RESULTFILE qq{
            <div class="$class">
            Folderset : $folderset->{folderset}<br>
            Docrange : $docs[0]->[0] - $docs[scalar(@docs)-1]->[0]<br>
            </div>
      };
   }
   $sth->finish;

   # Mark the end
   $sth = $dbh->prepare("UPDATE flags SET ended = now(), go = 0 WHERE go = 1 AND scriptname = ?");
   $sth->execute($scriptname) or die $dbh->errstr;
   $sth->finish;

   # Mark the end
   $tm = localtime;
   ($HOURS, $MIN) = ($tm->hour, $tm->min);

   print RESULTFILE_TXT "$today $HOURS:$MIN : ended.\n";
   print RESULTFILE qq{
<br>
$today $HOURS:$MIN : ended.<br>
</body>
</html>
};

   close(RESULTFILE);
   close(RESULTFILE_TXT);
   
   my $subject = "Pulaski RealEstate folderset.doc-range audit - ";
   $subject .= ($set) ? "$set::$today" : "All::$today";
   #qx{ /usr/bin/mail -s "$subject" $MAIL_LIST < $resultfile };

   #my $sender = new {
   #   smtp => 'xx.yy.com', 
   #   port => 2525,
   #   from => 'docindexer@xx.yy.com'
   #};
 
   my $sender = new {
      smtp => 'localhost', 
      from => 'docindexer@xxx.yyy.com'
   };
   
   $sender->MailFile({
      to      => 'abc@yyy.com', 
      cc      => 'marcel@yyy.com', 
      subject => $subject, 
      msg     => 'Plain text file and psychadelic-sixties-coloured file are attached.', 
      file    => "$resultfile,$resultfile.txt"
   });
}


########
# Start of script
########

$dbh = DBI->connect('dbi:mysql:xyz', $user, $passwd, { RaiseError => 1, AutoCommit => 1 }) || die "Unable to connect to database : $DBI::errstr\n";

# is there a task lined up for us ?
my $sth = $dbh->prepare("SELECT scriptname, started, ended, resultfile, IF (year = '','All', year) AS year FROM flags WHERE go = 1");
$sth->execute();
my $array_ref = $sth->fetchall_arrayref( { scriptname => 1, year => 1, started => 1, resultfile => 1 } );
foreach my $row (@$array_ref) { 
    # there is a task lined up for us
    if ($row->{scriptname}) {
       if (exists $DocIndexerTasks{$row->{scriptname}}) {
         # The task is known, so lets do this, if it's not already going
         &{$DocIndexerTasks{$row->{scriptname}}}($row->{resultfile}, $row->{scriptname}, $row->{year}) if $row->{started} =~ /0000-00-00 00:00:00/;
       }
    }
} 
$sth->finish;
$dbh->disconnect;
exit;
