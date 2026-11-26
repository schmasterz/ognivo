import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      "react-hooks/set-state-in-effect": "warn",
      "no-restricted-imports": [
        "error",
        {
          patterns: [
            {
              group: [
                "@locale/*",
                "**/locale/**",
                "**/i18n/locales/*",
                "**/i18n/dictionaries*",
              ],
              message:
                "Importing translation files directly is forbidden. Only src/lib/i18n/loaders.ts may import from @locale/*.",
            },
          ],
        },
      ],
    },
  },
  {
    files: [
      "src/lib/i18n/loaders.ts",
      "src/lib/i18n/I18nContext.tsx",
      "locale/**",
      "tests/**",
    ],
    rules: {
      "no-restricted-imports": "off",
      "import/no-anonymous-default-export": "off",
    },
  },
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
]);

export default eslintConfig;
