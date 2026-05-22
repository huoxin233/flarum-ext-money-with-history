export default class MoneyHistoryList extends Component<any, undefined> {
    constructor();
    oninit(vnode: any): void;
    loading: boolean | undefined;
    moreResults: boolean | undefined;
    historyEntries: any[] | undefined;
    user: any;
    view(): JSX.Element;
    loadMore(): void;
    parseResults(historyEntries: any): any;
    hasMoreResults(): boolean | undefined;
    loadResults(offset?: number): Promise<any>;
}
import Component from "flarum/common/Component";
