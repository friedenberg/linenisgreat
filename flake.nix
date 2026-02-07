{
  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/dcfec31546cb7676a5f18e80008e5c56af471925";
    nixpkgs-stable.url = "github:NixOS/nixpkgs/e9b7f2ff62b35f711568b1f0866243c7c302028d";
    utils.url = "https://flakehub.com/f/numtide/flake-utils/0.1.102";

    devenv-nix.url = "github:friedenberg/eng?dir=pkgs/alfa/devenv-php";
    devenv-php.url = "github:friedenberg/eng?dir=pkgs/alfa/devenv-php";
    devenv-shell.url = "github:friedenberg/eng?dir=pkgs/alfa/devenv-shell";
  };

  outputs =
    {
      self,
      nixpkgs,
      nixpkgs-stable,
      utils,
      devenv-nix,
      devenv-php,
      devenv-shell,
    }:
    (utils.lib.eachDefaultSystem (
      system:
      let

        pkgs = import nixpkgs {
          inherit system;
        };

      in
      {

        devShells.default = pkgs.mkShell {

          packages = (
            with pkgs;
            [
              bats
              composer
              fish
              gnumake
              gum
              just
            ]
          );

          inputsFrom = [
            devenv-nix.devShells.${system}.default
            devenv-php.devShells.${system}.default
            devenv-shell.devShells.${system}.default
          ];
        };
      }
    ));
}
