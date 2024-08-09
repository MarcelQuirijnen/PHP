#!/usr/bin/perl -w

=head1 NAME

        UserSummary.pl

=head1 SYNOPSIS

        /opt/configs/UserSummary.pl

=head1 AUTHOR

        Production Call Proc 12/2005

=head1 DESCRIPTION

        Get locations from publishers / caching publishers

=head1 REQUIREMENTS

        Perl 5.0.8 minimal (confirm with 'perl -v')
        MIME::Types
        MIME::Lite

=head1 INSTALLATION

        How about just cp the darn thing.

=cut

use DBI;
use Carp;
use Vonage;
use MIME::Lite;
use File::Basename;

my ($MAIL_LIST, $REPORT) = ('xxxx@vonage.com', '/tmp/user_distrib');
#my ($MAIL_LIST, $REPORT) = ('marcel.quirijnen@vonage.com', '/tmp/user_distrib');
my (%proxies, %Pubs, @ips) = ((),(),());
my ($PORT, $grouptotal) = (6004, 0);
my (@publishers, @groups) = ((),());
my @stripes = ('stripe1', 'stripe2', 'stripe3', 'stripe4');
my %strps = ( 'A' => 'stripe1', 'B' => 'stripe2', 'C' => 'stripe3', 'D' => 'stripe4', 'a' => 'stripe1');
my %OBs = ();
my %outbounds = ();
my $UNUSED='xx.xx.xx.xx';
my $ADD='xx.xx.xx.xx';

#my %OBs = ( 'group1' => {
#                stripe1 => { hostname => 'SomeHostname', privIP => '....', pubIP => '....' },
#                stripe2 => { hostname => '', privIP => '....', pubIP => ''  },
#                stripe3 => { hostname => '', privIP => '....', pubIP => ''  },
#                stripe4 => { hostname => '', privIP => '....', pubIP => ''  }
#            },
#          );


sub GetLocations
{
   my ($publisher) = @_;

   # Gene Gershanok script
   qx { /opt/configs/getlocations.pl $publisher $PORT >/opt/configs/locations/locations.$publisher };
}

sub ProcessLocations
{
   my ($publisher) = @_;
   my @data = ();

   open(LOCS, "</opt/configs/locations/locations.$publisher") || die "Couldn't open locations file\n";
   while (<LOCS>) {
      chomp;
      # not all info here is used yet
      # also, last line of datafile tends to be truncated for some reason
      @data = split(/\|/, $_, 6); 
      next if ! exists $outbounds{$data[2]};
      $proxies{$data[2]}++ if scalar(@data) >= 3;
   }
   close(LOCS);
   #unlink "/opt/configs/locations/locations.$publisher";
}


sub GenerateTotal
{
   my ($publisher) = @_;

   # take the scenic route to obtain totals. I prefer readable/maintainable code over fancy hacks
   foreach $group (@groups) {
      $grouptotal = 0;
      foreach $stripe (@stripes) {
         $grouptotal += $proxies{$OBs{$group}{$stripe}{'pubIP'}} if exists $proxies{$OBs{$group}{$stripe}{'pubIP'}};
      }
      push @{$Pubs{$publisher}}, $grouptotal;
   }
}

sub Initialize
{
   my ($data, $sql, $sth, $rv, $h, $p, $l);

   my $CPTools = DBI->connect('DBI:mysql:xxx', $CALLPROC, $CALLPROC_PASSWD) or die "$DBI::errstr\n";   
   #
   # get all proxies
   #
   $sql = "SELECT proxyIP FROM Config";
   $sth = $CPTools->prepare($sql);
   $rv = $sth->execute();
   while (($data) = $sth->fetchrow_array) {
      $outbounds{$data} = 1;
   } 
   $sth->finish();

   #
   # get all Pubs / Caching pubs
   #
   #push(@publishers, "10.31.30.22");

   $sql = "SELECT IP FROM Publisher";
   $sth = $CPTools->prepare($sql);
   $rv = $sth->execute();
   while (($data) = $sth->fetchrow_array) {
      push(@publishers, $data) if $data ne $UNUSED;
   } 
   $sth->finish();

   #
   # Get all groups
   #
   $sql = "SELECT ID FROM Groups WHERE ID > 0 AND ID < 1000";
   $sth = $CPTools->prepare($sql);
   $rv = $sth->execute();
   while (($data) = $sth->fetchrow_array) {
      push(@groups, $data);
   } 
   $sth->finish();
   pop(@groups);    # last group is not active yet
   
   #
   # Get the OBs info
   #
   $sql = "SELECT hostname, proxyIP, ListenIP FROM Config where GroupID > 0 and GroupID < 1000";
   $sth = $CPTools->prepare($sql);
   $rv = $sth->execute();
   while (($h, $p, $l) = $sth->fetchrow_array) {
      $group = $2 if $h =~ /([a-zA-Z\-])(\d+)([A-Da])/;
      $stripe = $strps{$3} if $h =~ /([a-zA-Z\-])(\d+)([A-Da])/;
      $OBs{$group}{$stripe}{'hostname'} = $h;
      $OBs{$group}{$stripe}{'privIP'} = $l;
      $OBs{$group}{$stripe}{'pubIP'} = $p;
   }
   $sth->finish();

   $CPTools->disconnect;
}


########################
# Start of code
########################

&Initialize;

foreach $publisher (@publishers) {
   GetLocations($publisher);
   ProcessLocations($publisher);
   GenerateTotal($publisher);
   %proxies = ();
}

open(REPORT, "+>$REPORT");
REPORT->autoflush(1);

# write header
print REPORT "GROUP\tPUBLISHER\tPUBLISHER" . "\tCASH-PUB" x (scalar(@publishers) - 2) . "\n";
print REPORT "     ";
foreach $publisher (@publishers) {
   print REPORT "\t$publisher";
}
print REPORT "\n";

# format the data ..
my %pubTotal = ();
foreach $group (@groups) {
   print REPORT "$group\t";
   #my $index = $1 if $group =~ /group(\d+)/;
   foreach $publisher (@publishers) {
      print REPORT "${$Pubs{$publisher}}[$group-1]\t";
      $pubTotal{$publisher} += ${$Pubs{$publisher}}[$group-1];
   }
   print REPORT "\n";
}
print REPORT "\nTOTAL";
foreach $publisher (@publishers) {
   print REPORT "\t$pubTotal{$publisher}";
}
print REPORT "\n";
close(REPORT);

# .. and send it as an attachement
my $report = MIME::Lite->new(
   From    => 'xxxx@xxx.vonage.net',
   To      => $MAIL_LIST,
   Subject => 'Proxy User distribution',
   Type    => 'multipart/mixed',
);
$report->attach(
   Type => 'TEXT',
   Data => 'User distribution data is attached.',
);
$report->attach(
   Type        => 'text/plain',
   Path        => $REPORT,
   Filename    => basename("$REPORT.xls"),
   Disposition => 'attachement',
);
$report->send;

unlink $REPORT;
exit;
