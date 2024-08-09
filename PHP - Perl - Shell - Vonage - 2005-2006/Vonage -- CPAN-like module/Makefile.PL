# -*- perl -*-
#
# Install package : Perl Makefile.PL
#                   make
#                   make install
#
# Use : perl Makefile.PL
#       Will install script in /usr/local/bin
#
# This package will be installed in the site specific tree
#   find `perl -Te 'print "@INC"'` -name '*.pm' -print
# to check this

use ExtUtils::MakeMaker;
use ExtUtils::Command;

@programs_to_install = ( );

# PREFIX defaults to '/usr/bin' .. see perl '-V:install.*'
$INSTALLSCRIPT='/usr/local/bin';

WriteMakefile(
   INSTALLSCRIPT => $INSTALLSCRIPT,
   NAME	     => 'Vonage',
   DISTNAME  => 'CallProc-modules',
   PREREQ_PM => { Expect => '1.15' },
   EXE_FILES => [ map {"$_"} @programs_to_install ],
   dist      => { SUFFIX   => '.gz', 
                  DIST_DEFAULT => 'all tardist',
                  COMPRESS => 'gzip -9f' 
                },
   VERSION_FROM => 'lib/Vonage.pm',
   MAN3PODS => { },     # suppress creation on man pages for now
   LIBS      => [''],
   DEFINE    => '',
   INC       => '',
   AUTHOR    => 'Prod Call Proc, prod_call_proc@vonage.com',
   ABSTRACT  => 'Installs basic Vonage utilities package'
);
