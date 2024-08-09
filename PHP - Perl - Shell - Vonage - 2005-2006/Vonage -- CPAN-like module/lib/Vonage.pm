package Vonage;

=head1 NAME

	Vonage.pm

=head1 SYNOPSIS

	use Vonage;

	my $session = Hop2Host('12.34.56.78', 'myLogin', 'MiP@sSw0rD');
        ...
        &Do_Thou_Thing;
        ...
        Cp2Host('12.34.56.78', 'myLogin', 'MiP@sSw0rD', 'mc', '/from/here.txt', '/to/here.txt');
        ...
        HopBackHome($session);

=head1 AUTHOR

        Production Call Proc 07/2005

=head1 DESCRIPTION

	Yeah, I should come up with some, but the code is very self explanatory.

=head1 REQUIREMENTS

	Perl will suffice               Available on a box near you
        Expect 1.14 or later            Available from expect.org

=head1 INSTALLATION

	The usual mantra :
		tar xzvBf Vonage-1.0.tar.gz
		perl Makefile.PL
		make && make install
	Did I skip a step ?

=cut

require Exporter;
use Expect;
@ISA = qw(Exporter);

@EXPORT = qw($VERSION HopToHost HopBackHome Cp2Host CloseConn $LINUS $LINUS_MC $NOC $NOC_PASSWD $YES $MAILER $MAIL_LIST @MC_NETS @TEST_NETS @SOFTPHONES %ED_RTPs);

our $VERSION = 1.01;

*LINUS = \'xx.xx.xx.xx';
*LINUS_MC = \'xx.xx.xx.x';
*NOC = \'...';
*NOC_PASSWD = \'...';
*YES = \'yes';
$MAILER = ($^O =~ m/linux/i) ? '/usr/bin/Mail' : '/usr/ucb/Mail';
*MAIL_LIST = \'xxxxx@vonage.com';

@MC_NETS    = ('xx\.xx\.xx\.', 'xx\.xx\.xx\.', 'xx\.xx\.xx\.');
@TEST_NETS  = ('xx\.xx\.xx\.xx\.', 'xx\.xx\.xx\.xx\.');
@SOFTPHONES = ('xx.xx.xx.x', 'xx.xx.xx.x');

%ED_RTPs = ( 'xx.xx.xx.x' => 'xx.xx.xx.x',
             'xx.xx.xx.x' => 'xx.xx.xx.x',
             'xx.xx.xx.xx' => 'xx.xx.xx.x',
             'xx.xx.xx.x' => 'xx.xx.xx.x'
           );


######################
# Expect helper function
# Not exported from module
######################
sub _exeFromHopper
{
   my ($exp, $contFromHopper, $host) = @_;

   $exp->send($contFromHopper);
   sleep 5;
   # very few systems need a timeout of 15 secs, but some do unfortunately
   # of course NetEng and/or Systems never got back to me on this issue
   $exp->expect(15,
                [ qr/want to continue connecting \(yes\/no\)\?/i => sub { my $exp = shift;
                                                                          $exp->send("$YES\n");
                                                                          exp_continue; } ],
                [ qr/'s password:/       => sub { my $exp = shift;
                                                  $exp->send("$NOC_PASSWD\n");
                                                  exp_continue; } ],
                [ qr/Password:/          => sub { my $exp = shift;
                                                  $exp->send("$NOC_PASSWD\n");
                                                  exp_continue; } ],
                [ qr/\[\w+@\w+.*\~\]\$/  => sub { exp_continue; }],
                [ qr/\r\n/               => sub { exp_continue; }],
                [ qr/.bash-.*/           => sub { exp_continue; }],
                [ qr/noc\]\$ /           => sub { exp_continue; }],
                [ qr/$host\r\n/          => sub { my $exp = shift;
                                                  $exp->send("$NOC_PASSWD\n");
                                                  exp_continue; } ],
               );
}


######################
# Expect helper function
# Not exported from module
######################
sub _exe2Hopper
{
   my ($exp, $hopcmd, $passwd) = @_;
   my @params = ();
   
   $exp->spawn($hopcmd, @params) || die "Cannot spawn $hopcmd: $!\n";
   sleep 2;
   $exp->expect(2, [ qr/want to continue connecting \(yes\/no\)\?/i => sub { my $exp = shift;
                                                                             $exp->send("$YES\n");
                                                                             exp_continue; } ],
                   [ qr/'s password: /     => sub { my $exp = shift;
                                                    $exp->send("$passwd\n");
                                                    exp_continue; } ],
                   [ qr/\[\w+@\w+.*\~\]\$/ => sub { exp_continue; }],
                   [ qr/01a:\~>/           => sub { exp_continue; }],
                   [ qr/\r\n/              => sub { exp_continue; }],
                   [ qr/bash-2.03\$ /      => sub { exp_continue; }],
                   [ qr/system maintanance will be posted here.  \n\n/ => sub { exp_continue; }],
               );
}


########################
# Setup a tunnel from this host to some other host !! through xxx/yy-xxx !!
########################
sub HopToHost
{
   my ($host, $user, $passwd) = @_;
   my $hopper = '';

   my $exp = new Expect;
   #$exp->debug(3);
   #$exp->exp_internal(3);
   $exp->raw_pty(1);
   $exp->log_stdout(0);

   foreach (@MC_NETS) {
      $hopper = 'xx' if $host =~ /$_/;
   }

   $user = $hopper eq 'xx' ? $NOC : $user;
   $passwd = $hopper eq 'xx' ? $NOC_PASSWD : $passwd;
   my $hophost = $hopper eq 'xx' ? $LINUS_MC : $LINUS;

   my $gotohop = "/usr/bin/ssh $user\@$hophost\n";
   _exe2Hopper($exp, $gotohop, $passwd);

   my $hopagain = $hopper eq 'xx' ? "/usr/bin/ssh $NOC\@$host\n" : "/usr/local/bin/ssh $NOC\@$host\n";
   _exeFromHopper($exp, $hopagain, $host);

   return($exp);
}


########################
# Go back home
########################
sub HopBackHome
{
   my ($expObj) = @_;

   $expObj->send("logout\n");
   sleep 1;
   $expObj->send("logout\n");
   CloseConn($expObj);
}

########################
# Close the Expect tunnel
########################
sub CloseConn
{
   my ($exp) = @_;
   $exp->soft_close();
}

########################
# programmed scp /dir/someFile someUser@someHost:/somedir
# scp command goes through xxx/xx-xxx
########################
sub Cp2Host
{
   my ($host, $user, $passwd, $frompath, $topath) = @_;
   my $hopper = '';
   
   use File::Basename;

   foreach (@MC_NETS) {
      $hopper = 'xx' if $host =~ /$_/;
   }

   $user = $hopper eq 'xx' ? $NOC : $user;
   $passwd = $hopper eq 'xx' ? $NOC_PASSWD : $passwd;
   my $hophost = $hopper eq 'xx' ? $LINUS_MC : $LINUS;

   my $exp = new Expect;
   $exp->raw_pty(1);
   $exp->log_stdout(0);
   my $cp2hop = "/usr/bin/scp $frompath $user\@$hophost:/tmp\n";
   _exe2Hopper($exp, $cp2hop, $passwd);
   CloseConn($exp);

   $exp = new Expect;
   #$exp->debug(3);
   #$exp->exp_internal(3);
   $exp->raw_pty(1);
   $exp->log_stdout(0);

   my $gotohop = "/usr/bin/ssh $user\@$hophost\n";
   _exe2Hopper($exp, $gotohop, $passwd);

   my $SCP = $hopper eq 'xx' ? '/usr/bin/scp' : '/usr/local/bin/scp';
   my $cp2host = "$SCP /tmp/" . basename($frompath) . " $NOC\@$host:$topath\n";
   _exeFromHopper($exp, $cp2host, $host);

   return($exp);
}


$| = 1;
1;
