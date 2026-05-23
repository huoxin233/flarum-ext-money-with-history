import UserPage from 'flarum/forum/components/UserPage';
import type Mithril from 'mithril';
import MoneyHistoryList from './MoneyHistoryList';

export default class MoneyHistoryPage extends UserPage {
  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    this.loadUser(m.route.param('username'));
  }

  content(): Mithril.Children {
    return (
      <div className="MoneyHistoryPage-content">
        <MoneyHistoryList params={{ user: this.user! }} />
      </div>
    );
  }
}
