// packages/php-wasm/web-builds/8-3/src/index.ts
import { jspi } from "wasm-feature-detect";
async function getPHPLoaderModule() {
  if (await jspi()) {
    return await import("./jspi/php_8_3.js");
  } else {
    return await import("./asyncify/php_8_3.js");
  }
}
async function getIntlExtensionPath() {
  if (await jspi()) {
    return (await import("./jspi/extensions/intl/intl.so?url")).default;
  } else {
    return (await import("./asyncify/extensions/intl/intl.so?url")).default;
  }
}
export {
  getIntlExtensionPath,
  getPHPLoaderModule,
  jspi
};
