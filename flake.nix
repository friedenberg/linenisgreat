{
  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/3e20095fe3c6cbb1ddcef89b26969a69a1570776";
    nixpkgs-master.url = "github:NixOS/nixpkgs/ae921939fcbd44874664477bd1d22543c10a8306";
    utils.url = "https://flakehub.com/f/numtide/flake-utils/0.1.102";

    just-us = {
      url = "github:amarbel-llc/just-us";
      inputs = {
        nixpkgs.follows = "nixpkgs";
        nixpkgs-master.follows = "nixpkgs-master";
        utils.follows = "utils";
      };
    };

    # The linter + formatter multiplexer (a treefmt superset). Deliberately NOT
    # following this flake's nixpkgs: treelint pins its own go_1_26 toolchain
    # and the amarbel-llc/nixpkgs (igloo) overlay, which overriding would break.
    treelint.url = "github:amarbel-llc/treelint";
  };

  outputs =
    {
      # always-available flake arg, unused here but kept for future use
      # deadnix: skip
      self,
      nixpkgs,
      # deliberate pin (just-us follows it), unused arg kept for future use
      # deadnix: skip
      nixpkgs-master,
      utils,
      just-us,
      treelint,
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

          packages = with pkgs; [
            actionlint
            ast-grep
            bats
            curl
            deadnix
            fish
            gh
            gnumake
            gum
            intelephense
            jq
            just-us.packages.${system}.default
            nixfmt-rfc-style
            php84
            php84Packages.composer
            php84Packages.php-cs-fixer
            phpstan
            prettier
            shellcheck
            shfmt
            statix
            stylelint
            taplo
            treelint.packages.${system}.default
          ];

        };
      }
    ));
}
