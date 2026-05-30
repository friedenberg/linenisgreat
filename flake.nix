{
  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/3e20095fe3c6cbb1ddcef89b26969a69a1570776";
    nixpkgs-master.url = "github:NixOS/nixpkgs/ae921939fcbd44874664477bd1d22543c10a8306";
    utils.url = "https://flakehub.com/f/numtide/flake-utils/0.1.102";

    just-us = {
      url = "github:amarbel-llc/just-us";
      inputs.nixpkgs.follows = "nixpkgs";
      inputs.nixpkgs-master.follows = "nixpkgs-master";
      inputs.utils.follows = "utils";
    };
  };

  outputs =
    {
      self,
      nixpkgs,
      nixpkgs-master,
      utils,
      just-us,
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
              just-us.packages.${system}.default
              php84
              php84Packages.composer
            ]
          );

        };
      }
    ));
}
