#!/usr/bin/perl -w

=head1 NAME

        mysql_optimize_tables.pl

=head1 SYNOPSIS

        /root/mysql_optimize_tables.pl [ -v -user ConnectToMySQLAsThisUser -passwd WithThisPassword ]

=head1 AUTHOR

        MQ, April 2009

=head1 DESCRIPTION

		Be verbose when -v
        Execute the following SQL statements on MySQL databases
          - show databases;
          - for each database do
                SHOW TABLE STATUS FROM <database> WHERE Data_free > 0;
                OPTIMIZE TABLE <database>.<table>;

=head1 REQUIREMENTS

        Perl 5.0.8 minimal (confirm with 'perl -v')
        DBI

=head1 INSTALLATION

        How about just cp the darn thing to where you want it.

=cut

use DBI;
use strict;

# preset values
my ($USER, $PASSWD, $VERBOSE) = ('....', '....', 0);

# override preset values
sub GetCommandLineOptions()
{
   my @args = @_;
   local $_;

   while (@args && ($_ = $args[0])) {
      if (/^-(\w+)/) {
         CASE : {
           if ($1 =~ /^user/)   { shift(@args); $USER = $args[0]; last CASE; }
           if ($1 =~ /^passwd/) { shift(@args); $PASSWD = $args[0]; last CASE; }
           if ($1 =~ /^v/)      { shift(@args); $VERBOSE = $args[0]; last CASE; }
         }
      } else {
         print "Oops: Unknown option : $_\n";
      }
      shift(@args);
   }
}

#
# start of code
#

&GetCommandLineOptions(@ARGV);

my $dbh = DBI->connect("DBI:mysql:mysql", $USER, $PASSWD, { PrintError => 1, AutoCommit => 1 }) or die "$DBI::errstr\n";

# Get a list of databases
my $dbs = $dbh->selectall_arrayref("SHOW DATABASES") || die $dbh->errstr;
foreach my $db (@$dbs) {
   # database name is in $db->[0]
   next if $db->[0] eq 'mysql';
   next if $db->[0] eq 'information_schema';
   print "Database $db->[0] :" if $VERBOSE;
   my $sql = "SHOW Table status FROM $db->[0] WHERE Data_free > 0";
   my $sth = $dbh->prepare($sql);
   $sth->execute;
   my (@data, $rv, $found) = ((), 0, 0);
   while (@data = $sth->fetchrow_array) {
      $found++; print "\n" if $VERBOSE;
      print "Optimizing $data[0] ... " if $VERBOSE;
      $rv = $dbh->do("OPTIMIZE TABLE $db->[0].`$data[0]`") or die $dbh->errstr;
      if ($VERBOSE) {
         print $rv ? "Done.\n" : "\n";
      } else {
         print "Optimized MySQL table : $db->[0].`$data[0]`\n";
      }
   }
   if ($VERBOSE) {
      print " Checked.\n" unless $found;
   }
   $sth->finish;
}

$dbh->disconnect;


