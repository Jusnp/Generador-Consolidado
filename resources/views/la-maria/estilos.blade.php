<style>
    .la-maria-result {
        margin-top: 20px;
        display: grid;
        gap: 12px;
    }

    .la-maria-result h3 {
        margin: 12px 0 0;
        font-size: 17px;
    }

    .la-maria-result-warning {
        margin: 0;
        padding: 12px 14px;
        border: 1px solid var(--border-soft);
        border-left: 4px solid var(--red);
        border-radius: 10px;
        color: var(--text-secondary);
        line-height: 1.6;
    }

    .la-maria-result details {
        padding: 10px 12px;
        border: 1px solid var(--border-soft);
        border-radius: 10px;
    }

    .la-maria-result summary {
        cursor: pointer;
        font-weight: 700;
    }

    .la-maria-table-wrap {
        overflow-x: auto;
        margin-top: 8px;
    }

    .la-maria-table-wrap.tall {
        max-height: 460px;
        overflow-y: auto;
    }

    .la-maria-table-wrap table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12.5px;
    }

    .la-maria-table-wrap th,
    .la-maria-table-wrap td {
        padding: 6px 8px;
        border-bottom: 1px solid var(--border-soft);
        text-align: left;
        vertical-align: top;
    }

    .la-maria-table-wrap th {
        position: sticky;
        top: 0;
        background: var(--surface-soft);
        white-space: nowrap;
    }

    .la-maria-table-wrap td.num {
        text-align: right;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }

    .la-maria-table-wrap td.cause {
        min-width: 320px;
        color: var(--text-secondary);
    }

    .la-maria-table-wrap tr.total td {
        font-weight: 700;
        background: var(--surface-soft);
    }

    .file-selection-summary {
        margin: 10px 0 0;
        font-weight: 700;
    }

    .file-selection-error {
        margin: 6px 0 0;
        color: var(--red);
        font-size: 13px;
    }

    .file-selection-error[hidden],
    .file-selection-summary[hidden] {
        display: none;
    }

    .file-selection-list {
        margin: 8px 0 0;
        padding-left: 22px;
        max-height: 260px;
        overflow-y: auto;
        font-size: 13px;
    }

    .file-selection-list li {
        padding: 3px 0;
    }

    .file-selection-list li span {
        margin-right: 10px;
    }

    .file-selection-remove {
        border: 0;
        background: none;
        color: var(--red);
        cursor: pointer;
        font-size: 12px;
        text-decoration: underline;
    }

    .file-selection-clear {
        margin-top: 8px;
    }
</style>
