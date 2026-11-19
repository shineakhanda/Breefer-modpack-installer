const React = window.React;
if (!React) throw new Error('window.React is not available for extension module rendering.');

import { Alert, Badge, Button, Card, Check, ConfirmButton, Empty, Field, Input, Loading, Modal, Page, Pager, Select, api, formatNumber, getServerId, timeAgo, toastErr, toastOk, useDebounced, } from './breefer-ui.js';
const { useState, useEffect } = React;
const base = () => `/api/client/extensions/breefer-modpack-installer/servers/${getServerId()}`;
const LABEL = { modrinth: 'Modrinth', curseforge: 'CurseForge', ftb: 'Feed The Beast' };
export default function ModpackPage() {
    const [status, setStatus] = useState(null);
    const [error, setError] = useState(null);
    const [provider, setProvider] = useState('modrinth');
    const [query, setQuery] = useState('');
    const [loader, setLoader] = useState('');
    const [version, setVersion] = useState('');
    const [sort, setSort] = useState('relevance');
    const [page, setPage] = useState(1);
    const debounced = useDebounced(query, 450);
    const debouncedVersion = useDebounced(version, 600);
    const [results, setResults] = useState(null);
    const [loading, setLoading] = useState(false);
    const [pack, setPack] = useState(null);
    const [versions, setVersions] = useState(null);
    const [choice, setChoice] = useState(null);
    const [wipe, setWipe] = useState(false);
    const [eula, setEula] = useState(true);
    const [starting, setStarting] = useState(false);
    const loadStatus = () => api('GET', base() + '/status')
        .then((s) => {
        setStatus(s);
        if (s.providers.length && !s.providers.includes(provider))
            setProvider(s.providers[0]);
        return s;
    })
        .catch((e) => {
        setError(e.message);
        return null;
    });
    useEffect(() => {
        loadStatus();
    }, []);
    // Poll while an installation is running.
    useEffect(() => {
        if (!status?.installing)
            return;
        const t = setInterval(() => {
            loadStatus().then((s) => {
                if (s && !s.installing)
                    toastOk('Modpack installation finished. You can start the server now.');
            });
        }, 8000);
        return () => clearInterval(t);
    }, [status?.installing]);
    useEffect(() => setPage(1), [debounced, provider, loader, debouncedVersion, sort]);
    useEffect(() => {
        if (!status || !status.providers.includes(provider))
            return;
        let alive = true;
        setLoading(true);
        const qs = new URLSearchParams({ provider, query: debounced, sort, page: String(page) });
        if (loader)
            qs.set('loader', loader);
        if (debouncedVersion)
            qs.set('version', debouncedVersion);
        api('GET', `${base()}/search?${qs}`)
            .then((d) => alive && setResults(d))
            .catch((e) => alive && toastErr(e.message))
            .finally(() => alive && setLoading(false));
        return () => {
            alive = false;
        };
    }, [status?.providers.join(','), provider, debounced, loader, debouncedVersion, sort, page]);
    const open = (p) => {
        setPack(p);
        setVersions(null);
        setChoice(null);
        api('GET', `${base()}/packs/${p.provider}/${encodeURIComponent(p.id)}/versions`)
            .then((d) => {
            setVersions(d.versions);
            setChoice(d.versions[0] ?? null);
        })
            .catch((e) => {
            toastErr(e.message);
            setVersions([]);
        });
    };
    const install = async () => {
        if (!pack || !choice)
            return;
        setStarting(true);
        try {
            await api('POST', base() + '/install', {
                provider: pack.provider,
                pack_id: pack.id,
                version_id: choice.id,
                delete_files: wipe,
                accept_eula: eula,
                title: pack.title,
                version_name: choice.name,
            });
            toastOk('Installation started — watch the console for progress.');
            setPack(null);
            await loadStatus();
        }
        catch (e) {
            toastErr(e.message);
        }
        finally {
            setStarting(false);
        }
    };
    const restore = async () => {
        try {
            await api('POST', base() + '/restore');
            toastOk('Server restored to its original egg.');
            await loadStatus();
        }
        catch (e) {
            toastErr(e.message);
        }
    };
    const running = status?.history.find((h) => h.status === 'running');
    return (React.createElement(Page, { title: 'Modpacks', subtitle: 'Install complete modpacks with the correct loader, mods and configs.', letter: 'P' },
        error ? React.createElement(Alert, { kind: 'err' }, error) : null,
        !status && !error ? React.createElement(Loading, null) : null,
        status ? (React.createElement(React.Fragment, null,
            status.installing ? (React.createElement(Alert, { kind: 'warn' },
                React.createElement("div", { className: 'brf-row brf-between' },
                    React.createElement("span", null,
                        "Installing ",
                        React.createElement("b", null, running?.meta?.title ?? 'a modpack'),
                        " ",
                        running?.meta?.version ? `(${running.meta.version})` : '',
                        " \u2014 started",
                        ' ',
                        timeAgo(running?.created_at),
                        ". Open the console to follow progress; the server is restored automatically when it finishes."),
                    React.createElement(ConfirmButton, { size: 'sm', variant: 'sec', title: 'Restore the server now?', message: 'Only do this if the installation is stuck. The server is switched back to its original egg immediately.', confirmText: 'Restore', onConfirm: restore }, "Restore")))) : null,
            status.providers.length === 0 ? (React.createElement(Empty, { title: 'No providers enabled', text: 'Ask your administrator to enable a modpack provider.' })) : (React.createElement(React.Fragment, null,
                React.createElement(Card, { style: { marginBottom: 14 } },
                    React.createElement("div", { className: 'brf-row' },
                        React.createElement(Field, { label: 'Source', style: { minWidth: 150 } },
                            React.createElement(Select, { value: provider, onValue: setProvider, options: status.providers.map((p) => ({ value: p, label: LABEL[p] ?? p })) })),
                        React.createElement(Field, { label: 'Loader', style: { minWidth: 130 } },
                            React.createElement(Select, { value: loader, onValue: setLoader, options: [{ value: '', label: 'Any' }, 'fabric', 'forge', 'neoforge', 'quilt'] })),
                        React.createElement(Field, { label: 'Minecraft version', style: { minWidth: 130 } },
                            React.createElement(Input, { value: version, onValue: setVersion, placeholder: 'e.g. 1.20.1' })),
                        provider !== 'ftb' ? (React.createElement(Field, { label: 'Sort', style: { minWidth: 140 } },
                            React.createElement(Select, { value: sort, onValue: setSort, options: [
                                    { value: 'relevance', label: 'Relevance' },
                                    { value: 'downloads', label: 'Downloads' },
                                    { value: 'updated', label: 'Recently updated' },
                                    { value: 'newest', label: 'Newest' },
                                ] }))) : null),
                    React.createElement(Input, { style: { marginTop: 12 }, placeholder: 'Search modpacks\u2026', value: query, onValue: setQuery })),
                !results ? (React.createElement(Loading, null)) : results.items.length === 0 ? (React.createElement(Empty, { title: 'No modpacks found', text: 'Try a different search or filter.' })) : (React.createElement(React.Fragment, null,
                    React.createElement("div", { className: 'brf-grid', style: { ['--brf-min']: '300px', opacity: loading ? 0.6 : 1 } }, results.items.map((p) => (React.createElement("div", { key: p.provider + p.id, className: 'brf-item brf-click', style: { alignItems: 'flex-start' }, onClick: () => open(p) },
                        p.icon ? React.createElement("img", { className: 'brf-thumb', src: p.icon, alt: '', loading: 'lazy', style: { width: 56, height: 56 } }) : React.createElement("div", { className: 'brf-thumb' }),
                        React.createElement("div", { className: 'brf-grow' },
                            React.createElement("p", { className: 'brf-title' }, p.title),
                            React.createElement("div", { className: 'brf-muted brf-clamp' }, p.description),
                            React.createElement("div", { className: 'brf-row', style: { gap: 6, marginTop: 6 } },
                                p.loaders.slice(0, 2).map((l) => (React.createElement(Badge, { key: l, kind: 'pri' }, l))),
                                p.game_versions.slice(0, 2).map((v) => (React.createElement(Badge, { key: v }, v))),
                                React.createElement("span", { className: 'brf-muted' },
                                    "\u2B07 ",
                                    formatNumber(p.downloads)))))))),
                    React.createElement(Pager, { page: page, pages: results.pages, onPage: setPage }))))))) : null,
        React.createElement(Modal, { open: !!pack, onClose: () => !starting && setPack(null), title: pack?.title, width: 640 }, pack ? (React.createElement("div", { className: 'brf-col' },
            React.createElement("div", { className: 'brf-row', style: { gap: 12 } },
                pack.icon ? React.createElement("img", { className: 'brf-thumb', src: pack.icon, alt: '', style: { width: 64, height: 64 } }) : null,
                React.createElement("div", { className: 'brf-grow brf-muted' },
                    pack.description,
                    pack.author ? React.createElement("div", null,
                        "by ",
                        pack.author) : null)),
            pack.url ? (React.createElement("a", { href: pack.url, target: '_blank', rel: 'noopener noreferrer' },
                "View on ",
                LABEL[pack.provider],
                " \u2197")) : null,
            !versions ? (React.createElement(Loading, null)) : versions.length === 0 ? (React.createElement(Empty, { title: 'No installable versions' })) : (React.createElement(React.Fragment, null,
                React.createElement(Field, { label: 'Version' },
                    React.createElement(Select, { value: choice?.id ?? '', onValue: (v) => setChoice(versions.find((x) => x.id === v) ?? null), options: versions.map((v) => ({
                            value: v.id,
                            label: `${v.name}${v.game_versions[0] ? ' — MC ' + v.game_versions[0] : ''}${v.loaders[0] ? ' · ' + v.loaders[0] : ''}${v.type !== 'release' ? ' (' + v.type + ')' : ''}`,
                        })) })),
                choice?.java || choice?.memory ? (React.createElement("div", { className: 'brf-row' },
                    choice.java ? React.createElement(Badge, { kind: 'warn' },
                        "Needs Java ",
                        choice.java) : null,
                    choice.memory ? React.createElement(Badge, null,
                        "Recommended RAM ",
                        Math.round(choice.memory / 1024),
                        " GB") : null)) : null,
                React.createElement(Alert, { kind: 'info', style: { marginBottom: 0 } },
                    "The server is stopped and reinstalled with the modpack. Existing ",
                    React.createElement("code", null, "mods/"),
                    " and loader libraries are replaced; your worlds are kept unless you choose to delete all files. Make sure the server's Java image matches the pack."),
                React.createElement(Check, { checked: wipe, onValue: setWipe, label: 'Delete ALL server files first (fresh install, worlds included)' }),
                React.createElement(Check, { checked: eula, onValue: setEula, label: 'I accept the Minecraft EULA' }),
                React.createElement("div", { className: 'brf-row brf-end' },
                    React.createElement(Button, { variant: 'sec', disabled: starting, onClick: () => setPack(null) }, "Cancel"),
                    React.createElement(Button, { variant: wipe ? 'danger' : 'primary', loading: starting, disabled: !choice || status?.installing, onClick: install }, "Install modpack")))))) : null)));
}
