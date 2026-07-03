/**
 * Hermiq landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the OpenRegister
 * landing page at openregister.conduction.nl (docs/src/pages/index.js).
 *
 * Replace the placeholder glyph, tagline, and the three mock widget
 * panels below with hermiq's real surface (use <AppMock app="…" />
 * from the preset once hermiq has a registered mock variant).
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude — likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
} from '@conduction/docusaurus-preset/components';

/* Placeholder glyph — a generic stroke "stacked layers" mark. Swap
   for hermiq's Material Design Icons glyph (the same path used in
   `img/app.svg`). */
const HERMIQ_ICON = (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M12 3 3 8l9 5 9-5-9-5z" />
    <path d="M3 16l9 5 9-5" />
    <path d="M3 12l9 5 9-5" />
  </svg>
);

const TAGLINE = (
  <>
    Hermiq brings autonomous AI agents to Nextcloud. Define an agent,
    give it tools, and run it on a schedule — all inside your own
    Nextcloud. Agents, their memory and skills are stored as
    OpenRegister objects, so every run is governed, auditable and
    multi-tenant.
  </>
);

/* --- Generic mock widget panels --------------------------------------
   Token-only abstractions of the surfaces a fresh app starts with.
   Replace these with your app's real widget mocks (or <AppMock>) once
   the UI exists. */

function DashboardPanel() {
  const rows = [
    { tone: 'var(--c-cobalt-300)', w: '70%' },
    { tone: 'var(--c-lavender-300)', w: '55%' },
    { tone: 'var(--c-mint-500)', w: '40%' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span
            style={{
              width: 14,
              height: 16,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              height: 6,
              background: 'var(--c-cobalt-50)',
              borderRadius: 1,
              overflow: 'hidden',
            }}
          >
            <div
              style={{
                height: '100%',
                width: row.w,
                background: 'var(--c-cobalt-300)',
                borderRadius: 1,
              }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

function ManifestPanel() {
  const lines = ['{', '  "pages": [ … ],', '  "menu": [ … ],', '  "dependencies": [ … ]', '}'];
  return (
    <div
      style={{
        fontFamily: 'var(--conduction-typography-font-family-code)',
        fontSize: 10,
        lineHeight: 1.6,
        color: 'var(--c-cobalt-700)',
        display: 'flex',
        flexDirection: 'column',
        gap: 2,
      }}
    >
      {lines.map((line, i) => (
        <div key={i}>{line}</div>
      ))}
    </div>
  );
}

function SettingsPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', on: true },
    { tone: 'var(--c-cobalt-300)', on: true },
    { tone: 'var(--c-cobalt-100)', on: false },
    { tone: 'var(--c-cobalt-100)', on: false },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      {rows.map((row, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <div
            style={{
              flex: 1,
              height: 4,
              width: '60%',
              background: 'var(--c-cobalt-200)',
              borderRadius: 1,
            }}
          />
          <div
            style={{
              width: 22,
              height: 12,
              borderRadius: 6,
              background: row.on ? row.tone : 'var(--c-cobalt-50)',
              position: 'relative',
              flexShrink: 0,
            }}
          >
            <span
              style={{
                position: 'absolute',
                top: 2,
                left: row.on ? 12 : 2,
                width: 8,
                height: 8,
                borderRadius: '50%',
                background: '#fff',
              }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Dashboard widget, ready to copy',
    desc: 'Hermiq ships a working ExampleWidget — PHP IWidget class, webpack entry, and a NcDashboardWidget renderer. Three files plus two registration points; rename and replace the data.',
    panel: <DashboardPanel />,
  },
  {
    title: 'Manifest-first, no per-page Vue',
    desc: 'Pages, navigation, and dependencies live in src/manifest.json. CnAppRoot reads the manifest at boot and renders index / detail / dashboard / settings pages — you only write a Vue file for type: "custom".',
    panel: <ManifestPanel />,
  },
  {
    title: 'Admin settings + AI companion',
    desc: 'An admin settings panel wired through NcAppSettingsDialog, an OpenRegister-backed settings register, and an ExampleToolProvider that exposes the app over MCP to the in-app AI Chat Companion.',
    panel: <SettingsPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="Hermiq — schedule autonomous AI agents in Nextcloud"
      description="Hermiq brings autonomous AI agents to Nextcloud. Define an agent, give it tools, and run it on a schedule — all inside your own Nextcloud."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          status={{ label: 'Beta', color: 'var(--c-orange-knvb)' }}
          version="v0.1"
          locales="NL · EN"
          title="Hermiq"
          tagline={TAGLINE}
          primaryCta={{
            label: 'View on Codeberg',
            href: 'https://codeberg.org/Conduction/hermiq',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          iconColor="var(--c-orange-knvb)"
          icon={HERMIQ_ICON}
        />

        <WidgetShelf
          eyebrow="What you start with"
          title="Agents, memory and skills — all governed by OpenRegister."
          lede="Define an agent, give it tools, and run it on a schedule — a Dashboard widget, a manifest-driven UI, an admin settings panel, an MCP tool provider, and this very documentation site all come wired up."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
