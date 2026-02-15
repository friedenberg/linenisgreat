{
  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/23d72dabcb3b12469f57b37170fcbc1789bd7457";
    nixpkgs-master.url = "github:NixOS/nixpkgs/b28c4999ed71543e71552ccfd0d7e68c581ba7e9";
    utils.url = "https://flakehub.com/f/numtide/flake-utils/0.1.102";

    devenv-php.url = "github:friedenberg/eng?dir=devenvs/php";
  };

  outputs =
    { self
    , nixpkgs
    , nixpkgs-master
    , utils
    , devenv-php
    ,
    }:
    (utils.lib.eachDefaultSystem (
      system:
      let

        pkgs = import nixpkgs {
          inherit system;

          config.allowUnfree = true;
        };

      in
      {

        devShells.default = pkgs.mkShell {

          packages = (
            with pkgs;
            [
              bats
              fish
              gnumake
              gum
              intelephense
              just
              php84
              php84Packages.composer
            ]
          );

          inputsFrom = [
            devenv-php.devShells.${system}.default
          ];
        };
      }
    ));
}
