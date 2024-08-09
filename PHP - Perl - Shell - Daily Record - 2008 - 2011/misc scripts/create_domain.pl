#!/usr/bin/perl -w

=head1 NAME

        create_domain.pl

=head1 SYNOPSIS

        /root/create_domain.pl -domain NewDomainToBeCreated.org
                               [ -user ConnectToMySQLAsThisUser -passwd WithThisPassword ]

=head1 AUTHOR

        MQ, April 2009

=head1 DESCRIPTION

        Create mail domain in Mysql mailserver.virtual_domains table.
        As we use Horde Groupware as our webmail application, this domain needs to be created upfront
        since Horde doesn't support multi-domains. Looking into RoundCube to possibly solve this.

=head1 REQUIREMENTS

        Perl 5.0.8 minimal (confirm with 'perl -v')
        DBI

=head1 INSTALLATION

        How about just cp the darn thing to where you want it.

=cut

use DBI;
use strict;

# preset values
my ($DB, $USER, $PASSWD, $TABLE, $DOMAIN) = ('....', '....', '......', '.....', '.....');

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
           if ($1 =~ /^domain/) { shift(@args); $DOMAIN = $args[0]; last CASE; }
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
my ($id, $domain) = (0, '');

&GetCommandLineOptions(@ARGV);

die "Please specify a domain to create!" unless $DOMAIN;

my $drmail = DBI->connect("DBI:mysql:$DB", $USER, $PASSWD) or die "$DBI::errstr\n";

# Check if specified domain exists
my $sql = "SELECT * FROM $TABLE WHERE name = ?";
my $sth = $drmail->prepare($sql);
$sth->execute($DOMAIN) || die $drmail->errstr;
($id, $domain) = $sth->fetchrow_array;
if ($id) {
   $sth->finish();
   $drmail->disconnect;  
   die "Sorry. Domain already exists: ", $domain, " (id = ", $id, ")\n"; 
} 
$sth->finish();

#ok. Domain does not exist, let's create it
$sql = "INSERT INTO $TABLE VALUES ( null, ?)";
$sth = $drmail->prepare($sql);
$sth->execute($DOMAIN) || die $drmail->errstr;
$sth->finish();

#Let's check we really did it
$sql = "SELECT * FROM $TABLE WHERE name = ?";
$sth = $drmail->prepare($sql);
$sth->execute($DOMAIN) || die $drmail->errstr;
($id, $domain) = $sth->fetchrow_array;
print $id ? "Domain created successfully : $domain (id = $id)\n" : "Domain NOT created.";
$sth->finish();

$drmail->disconnect;


