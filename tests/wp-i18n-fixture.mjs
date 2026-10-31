import { readFileSync } from "node:fs";
import { runInNewContext } from "node:vm";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

export function installI18n(window) {
  const root = process.env.SF_WORDPRESS_TEST_ROOT
    ? pathToFileURL(resolve(process.env.SF_WORDPRESS_TEST_ROOT) + "/")
    : new URL("../.local/wordpress/", import.meta.url);
  for (const file of ["hooks.js", "i18n.js"]) {
    runInNewContext(readFileSync(new URL(`wp-includes/js/dist/${file}`, root), "utf8"), { window, console });
  }
  return window.wp.i18n;
}
